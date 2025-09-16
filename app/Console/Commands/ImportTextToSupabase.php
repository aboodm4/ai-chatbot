<?php

namespace App\Console\Commands;

use App\Services\AiSupportService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportTextToSupabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-text-to-supabase';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $aiSupportService;

    public function __construct(AiSupportService $aiSupportService)
    {
        parent::__construct();
        $this->aiSupportService = $aiSupportService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fileName = storage_path('app/texts/parcel.md');
        if (!File::exists($fileName)) {
            $this->error("File not found: $fileName");
            return 1;
        }
        $text = File::get($fileName);

        // Divide into semantic chunks (~500 chars each)
        $chunks = $this->createSemanticChunks($text, 500);
        if (empty($chunks)) {
            $this->error(" Could not split the document into meaningful chunks.");
            return 1;
        }


        // Load Gemini API key from env
        $geminiApiKey = env('GEMINI_API_KEY');
        if (!$geminiApiKey) {
            $this->error("GEMINI_API_KEY not set in .env");
            return 1;
        }

        // $embeddingModel = "gemini-embedding-001";
        $savedData = [];
        $embeddingModel = 'text-embedding-004';
        $geminiApiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$embeddingModel}:embedContent?key={$geminiApiKey}";

        foreach ($chunks as $index => $chunk) {
            $trimmedChunk = trim(str_replace(["\r", "\n"], ' ', $chunk));
            if (empty($trimmedChunk)) {
                $this->warn("⚠️ Skipping empty chunk #{$index}");
                continue;
            }

            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->post($geminiApiUrl, [
                        'model' => "models/{$embeddingModel}",
                        'content' => ['parts' => [['text' => $trimmedChunk]]]
                    ]);
                if ($response->failed()) {
                    $this->error("Failed to embed chunk");
                    continue;
                }

                $embedding = $response->json()['embedding']['values'] ?? null;
                if (!$embedding) {
                    $this->error("❌ Invalid embedding returned for chunk #{$index}.");
                    continue;
                }

                $savedData[] = [
                    'content' => $trimmedChunk,
                    'embedding' => json_encode(value: $embedding) // Format the vector array into a JSON string
                ];

            } catch (Exception $e) {
                $this->error("An exception occurred while processing chunk #{$index}: " . $e->getMessage());
            }
        }

        $this->saveDataToSupabase($savedData);
        
        $this->info("Import completed successfully!");
        return 0;
    }





    /**
     * Divide text into semantic chunks.
     *
     * @param string $text The full text to split
     * @param int $chunkSize Maximum characters per chunk (default 500)
     * @return array An array of text chunks
     */
    public function createSemanticChunks(string $text, int $chunkSize = 500): array
    {
        $chunks = [];
        $paragraphs = preg_split('/\n+/', $text); // قسم النص على فقرات

        $currentChunk = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            // لو طول النص الحالي + الفقرة أكبر من الحد، خزّن chunk وابدأ جديد
            if (strlen($currentChunk . ' ' . $para) > $chunkSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $para; // ابدأ chunk جديد
            } else {
                $currentChunk .= ' ' . $para;
            }
        }

        // خزّن آخر chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }




    public function saveDataToSupabase($data)
    {

        try {
            $dbConnection = DB::connection('pgsql_supabase');
            // $this->line($dbConnection == true ? "successssssssssssss":"");
            $dbConnection->table('documents')->truncate();

            $dbConnection->table('documents')->insert($data);
        } catch (Exception $e) {
            Log::info("an errror is ".$e->getMessage());

            $this->error('❌ An exception occurred during Supabase insert');
        }
    }
}
