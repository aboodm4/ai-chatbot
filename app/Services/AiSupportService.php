<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiSupportService
{
    public function askGemini(string $question): string
    {
        // Log::info("the question is: " . $question);

        //get embedding of the question
        $questionEmbedding = $this->getEmbedding($question);
        // Log::info("the questionEmbedding is: ", $questionEmbedding);

        //compare the embedding question with my embedding data in database and return content
        $databaseContent = $this->getContentFromDatabase($questionEmbedding);
        // Log::info("the databaseContent is: " . $databaseContent);
        // Log::info('the databaseContent is: ', $databaseContent); // Laravel supports array context

        $contextString = implode("\n", $databaseContent);


        //send my content and question to gemini ai to give me a good answer
        $answer = $this->generateFinalAnswer($question, $contextString);
        Log::info("the generateFinalAnswer is: " . $answer);

        if (empty($answer)) {
            return "not found";
        }
        //format answer text and remove sembols
        $formattedAnswer = $this->formatText($answer);


        return $formattedAnswer;
    }

    /**
     * Create embedding for a given text using Gemini API.
     *
     * @param string $text
     * @return array
     * @throws Exception
     */
    public function getEmbedding(string $text): array
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new Exception('Gemini API Key is not configured.');
        }

        $embeddingModel = 'text-embedding-004';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$embeddingModel}:embedContent?key={$apiKey}";

        $response = Http::post($url, [
            'model' => "models/{$embeddingModel}",
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ]
        ]);

        if ($response->failed()) {
            throw new Exception("Failed to create embedding: " . $response->body());
        }

        // العودة بمصفوفة الأرقام (vector)
        // Log::info("the get embedding from ai :". $response);
        return $response->json()['embedding']['values'] ?? [];
    }

    public function getContentFromDatabase(array $embeddingQuestion)
    {
        try {
            // $embeddingQuestion = json_encode($embeddingQuestion);
            $dbConnection = DB::connection('pgsql_supabase');
            $dbConnection->statement('SET search_path TO public, extensions');

            // Convert PHP array into a Postgres vector string
            $embeddingString = $this->toPgVector($embeddingQuestion);

            $qry = $dbConnection->table('documents')
                ->select("content")
                ->orderByRaw('embedding <=> ?::vector', [$embeddingString])
                ->limit(5)
                ->get();

            $results = $qry->pluck('content')->toArray();
        } catch (Exception $e) {
            Log::error(' Error ' . $e->getMessage());
            return 'error';
        }

        return $results;
    }


    private function toPgVector(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }
    private function formatText($text): string
    {
        // $newAnswer = str_replace('\\n', "\n", $answer); // convert escaped \n to actual newlines
        // $newAnswer = trim($newAnswer); // remove leading/trailing whitespace
        // $newAnswer = preg_replace('/\*\*(.*?)\*\*/', '$1', $newAnswer);
        // Trim whitespace
        $text = trim($text);
        // 2. Remove literal \n and \n\n
        $text = str_replace(['\\n', "\n", "\r\n", "\r"], ' ', $text);

        // 3. Remove **bold** markers
        $text = preg_replace('/\*\*(.*?)\*\*/u', '$1', $text);
        $text = preg_replace('/\s*\*\s*/', ' ', $text);

        // 4. Collapse multiple spaces into a single space
        $text = preg_replace('/\s+/', ' ', $text);
        // Replace literal "\n" with actual newlines
        $text = str_replace(['\\n', '\n'], "\n", $text);

        // Replace "* " at the start of lines with bullet •
        $text = preg_replace('/^\s*\*\s+/m', "• ", $text);

        // Remove double spaces
        $text = preg_replace('/ +/', ' ', $text);

        // 3. Remove **bold** markers
        $text = preg_replace('/\*\*(.*?)\*\*/u', '$1', $text);

        // 5. Remove extra blank lines (more than 2)
        $text = preg_replace("/\n{2,}/", "\n\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return $text;
    }

    public function generateFinalAnswer(string $question, string $context)
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new Exception('Gemini API Key is not configured.');
        }

        $model  = 'gemini-1.5-flash';
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = $this->buildCustomerSupportPrompt($question, $context);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ]);

        if ($response->status() == 429) {
            return "لقد تجاوزنا الحد المسموح به من الطلبات حالياً. الرجاء المحاولة بعد قليل.";
        }
        if ($response->failed()) {
            Log::error('Gemini API Response: ' . $response->body());

            throw new Exception("error in generate final answer in gemini-1.5");
        }

        $data = $response->json()['candidates'][0]['content']['parts'][0]['text']
            ?? "Sorry, I couldn't generate an answer right now.";

        return $data;
    }

    function buildCustomerSupportPrompt(string $question, string $context): string
    {
        return "أنت مساعد دعم عملاء مفيد ويجيب بطريقة واضحة ومهذبة وموجزة.
                العميل سأل: \"$question\"

                استخدم المعلومات التالية من قاعدة البيانات للإجابة على السؤال:
                \"$context\"

                - لخص وشرح المعلومات المهمة للعميل بطريقة سهلة الفهم.
                - إذا لم تغطي المعلومات كل تفاصيل السؤال، قدم أفضل إجابة ممكنة باستخدام المعلومات المتاحة.
                - قدم توجيهات أو تعليمات مفيدة بناءً على البيانات، ولا تقول فقط أنك لا تعرف.
                - حافظ على الإجابة احترافية وسهلة القراءة.
                - نسق الإجابة باستخدام فواصل الأسطر أو القوائم النقطية لتكون واضحة.";
    }
}
