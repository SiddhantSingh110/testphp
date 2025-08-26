<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AISummaryService {
    
    /**
     * Generate a medical summary with provider switching based on ENV settings
     */
    public static function generateSummary($rawText)
    {
        try {
            // 🔥 NEW: Check if OpenAI is enabled in ENV
            $openaiEnabled = config('services.openai.enabled', env('OPENAI_ENABLED', false));
            $primaryProvider = env('PRIMARY_AI_PROVIDER', 'openai');
            $secondaryProvider = env('SECONDARY_AI_PROVIDER', 'claude');
            
            Log::info("🤖 AISummaryService starting with ENV-based provider selection", [
                'openai_enabled' => $openaiEnabled,
                'primary_provider' => $primaryProvider,
                'secondary_provider' => $secondaryProvider,
                'text_length' => strlen($rawText),
                'text_preview' => substr($rawText, 0, 300),
                'encoding_valid' => mb_check_encoding($rawText, 'UTF-8'),
                'contains_medical_terms' => self::containsMedicalTerms($rawText),
                'has_numbers' => preg_match('/\d+/', $rawText) ? true : false
            ]);

            // Clean and validate text
            $cleanedText = self::cleanTextForAI($rawText);
            
            Log::info("🧹 Text cleaned for AI processing", [
                'original_length' => strlen($rawText),
                'cleaned_length' => strlen($cleanedText),
                'encoding_valid' => mb_check_encoding($cleanedText, 'UTF-8'),
                'cleaned_preview' => substr($cleanedText, 0, 300)
            ]);

            // 🔥 NEW: Try primary provider first
            $summary = null;
            $providerUsed = null;
            
            try {
                if ($primaryProvider === 'openai' && $openaiEnabled) {
                    Log::info("🚀 Trying PRIMARY provider: OpenAI");
                    $summary = self::processWithOpenAI($cleanedText);
                    $providerUsed = 'openai';
                } elseif ($primaryProvider === 'deepseek') {
                    Log::info("🚀 Trying PRIMARY provider: DeepSeek");
                    $summary = self::processWithDeepSeek($cleanedText);
                    $providerUsed = 'deepseek';
                } elseif ($primaryProvider === 'claude') {
                    Log::info("🚀 Trying PRIMARY provider: Claude");
                    $summary = self::processWithClaude($cleanedText);
                    $providerUsed = 'claude';
                } else {
                    throw new \Exception("Primary provider '{$primaryProvider}' is not available or disabled");
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Primary provider failed, trying secondary", [
                    'primary_provider' => $primaryProvider,
                    'error' => $e->getMessage()
                ]);
                
                // Try secondary provider
                try {
                    if ($secondaryProvider === 'openai' && $openaiEnabled) {
                        Log::info("🔄 Trying SECONDARY provider: OpenAI");
                        $summary = self::processWithOpenAI($cleanedText);
                        $providerUsed = 'openai';
                    } elseif ($secondaryProvider === 'deepseek') {
                        Log::info("🔄 Trying SECONDARY provider: DeepSeek");
                        $summary = self::processWithDeepSeek($cleanedText);
                        $providerUsed = 'deepseek';
                    } elseif ($secondaryProvider === 'claude') {
                        Log::info("🔄 Trying SECONDARY provider: Claude");
                        $summary = self::processWithClaude($cleanedText);
                        $providerUsed = 'claude';
                    } else {
                        throw new \Exception("Secondary provider '{$secondaryProvider}' is not available or disabled");
                    }
                } catch (\Exception $e2) {
                    Log::error("❌ Both providers failed", [
                        'primary_error' => $e->getMessage(),
                        'secondary_error' => $e2->getMessage()
                    ]);
                    throw new \Exception("All AI providers failed: Primary({$primaryProvider}): {$e->getMessage()}, Secondary({$secondaryProvider}): {$e2->getMessage()}");
                }
            }
            
            // Validate decoded summary
            if (!is_array($summary)) {
                Log::error("🚨 AI returned invalid response", [
                    'provider_used' => $providerUsed,
                    'response_type' => gettype($summary),
                    'response_preview' => is_string($summary) ? substr($summary, 0, 500) : 'not_string'
                ]);
                throw new \Exception('AI returned an invalid or non-JSON response.');
            }
            
            Log::info("✅ AI processing successful", [
                'provider_used' => $providerUsed,
                'summary_keys' => array_keys($summary),
                'has_key_findings' => isset($summary['key_findings']),
                'key_findings_count' => isset($summary['key_findings']) ? count($summary['key_findings']) : 0,
                'confidence_score' => $summary['confidence_score'] ?? 'not_set'
            ]);

            // Process the key findings to ensure proper status assignment
            if (isset($summary['key_findings']) && is_array($summary['key_findings'])) {
                $originalFindings = $summary['key_findings'];
                $summary['key_findings'] = self::processKeyFindings($summary['key_findings']);
                
                Log::info("🔍 Key findings processed", [
                    'original_findings' => $originalFindings,
                    'processed_findings' => $summary['key_findings'],
                    'findings_count' => count($summary['key_findings'])
                ]);
            }
            
            // Extract patient info if not already present
            if (!isset($summary['patient_name']) || $summary['patient_name'] === 'N/A') {
                $patientInfo = self::extractPatientInfo($cleanedText, $providerUsed);
                $summary = array_merge($patientInfo, $summary);
            }
            
            // Translate to Hindi
            $hindiVersion = self::translateToHindi($summary, $providerUsed);
            
            // Add percentage symbol to confidence score if needed
            if (isset($summary['confidence_score']) && is_numeric($summary['confidence_score'])) {
                $summary['confidence_score'] = $summary['confidence_score'] . '%';
            }
            
            $finalSummary = [
                ...$summary,
                'hindi_version' => $hindiVersion,
                'provider_used' => $providerUsed,
            ];

            Log::info("🎉 AI summary generation completed successfully", [
                'provider_used' => $providerUsed,
                'final_confidence' => $finalSummary['confidence_score'] ?? 'not_set',
                'final_findings_count' => isset($finalSummary['key_findings']) ? count($finalSummary['key_findings']) : 0,
                'has_patient_info' => isset($finalSummary['patient_name']) && $finalSummary['patient_name'] !== 'N/A'
            ]);
            
            return $finalSummary;
            
        } catch (\Exception $e) {
            Log::error("🚨 AI summary generation failed completely", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'text_length' => strlen($rawText),
                'text_preview' => substr($rawText, 0, 200)
            ]);
            
            return [
                'patient_name' => 'N/A',
                'patient_age' => 'N/A',
                'patient_gender' => 'N/A',
                'diagnosis' => 'Analysis failed - please try again',
                'key_findings' => ['Unable to analyze medical report'],
                'recommendations' => ['Please re-upload the report or contact support'],
                'confidence_score' => '0%',
                'hindi_version' => 'N/A',
                'provider_used' => 'failed',
            ];
        }
    }
    
    /**
     * 🔥 NEW: Process with OpenAI
     */
    private static function processWithOpenAI($cleanedText)
    {
        if (!env('OPENAI_ENABLED', false)) {
            throw new \Exception('OpenAI is disabled in ENV configuration');
        }
        
        $client = OpenAI::client(env('OPENAI_API_KEY'));
        
        $isMedicalReport = self::containsMedicalTerms($cleanedText);
        
        $enhancedPrompt = "You are a medical AI assistant. Analyze this medical report and extract health data in JSON format.

IMPORTANT INSTRUCTIONS:
1. Look for ANY numerical values with units (mg/dL, mmol/L, g/dL, %, etc.)
2. Include ALL test results, even if they appear normal
3. Extract patient demographic information
4. Provide a confidence score above 50% if you find any medical data
5. DO NOT return 'N/A' for key_findings - extract actual test results

Required JSON structure:
{
  \"patient_name\": \"[Full patient name]\",
  \"patient_age\": \"[Age with units]\", 
  \"patient_gender\": \"[male/female/other]\",
  \"diagnosis\": \"[Brief medical summary]\",
  \"key_findings\": [
    {
      \"finding\": \"[Test name like 'Total Cholesterol']\",
      \"value\": \"[Numerical value with unit like '180 mg/dL']\",
      \"reference\": \"[Normal range like '<200 mg/dL']\",
      \"status\": \"[normal/borderline/high]\",
      \"description\": \"[Clear explanation]\"
    }
  ],
  \"recommendations\": [\"[Actionable medical advice]\"],
  \"confidence_score\": [Number 0-100 without % symbol]
}

Medical Report Text:
{$cleanedText}

Extract ALL numerical test results you can find. Return valid JSON only.";

        Log::info("🤖 Sending prompt to OpenAI", [
            'prompt_length' => strlen($enhancedPrompt),
            'contains_medical_keywords' => $isMedicalReport
        ]);

        $response = $client->chat()->create([
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical expert AI assistant. Always return valid JSON with actual medical findings, never return N/A for key_findings.'],
                ['role' => 'user', 'content' => $enhancedPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 2000,
        ]);
        
        $content = $response->choices[0]->message->content;
        
        Log::info("🤖 OpenAI raw response", [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 500),
            'contains_json' => strpos($content, '{') !== false
        ]);
        
        return json_decode($content, true);
    }
    
    /**
     * 🔥 NEW: Process with DeepSeek
     */
    private static function processWithDeepSeek($cleanedText)
    {
        if (!env('DEEPSEEK_ENABLED', true)) {
            throw new \Exception('DeepSeek is disabled in ENV configuration');
        }
        
        $apiKey = env('DEEPSEEK_API_KEY');
        $baseUrl = env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com');
        $model = env('DEEPSEEK_MODEL', 'deepseek-chat');
        
        if (!$apiKey) {
            throw new \Exception('DeepSeek API key not configured');
        }
        
        $prompt = "You are a medical AI assistant. Analyze this medical report and extract health data in JSON format.

IMPORTANT INSTRUCTIONS:
1. Look for ANY numerical values with units (mg/dL, mmol/L, g/dL, %, etc.)
2. Include ALL test results, even if they appear normal
3. Extract patient demographic information
4. Provide a confidence score above 50% if you find any medical data
5. DO NOT return 'N/A' for key_findings - extract actual test results

Required JSON structure:
{
  \"patient_name\": \"[Full patient name]\",
  \"patient_age\": \"[Age with units]\", 
  \"patient_gender\": \"[male/female/other]\",
  \"diagnosis\": \"[Brief medical summary]\",
  \"key_findings\": [
    {
      \"finding\": \"[Test name like 'Total Cholesterol']\",
      \"value\": \"[Numerical value with unit like '180 mg/dL']\",
      \"reference\": \"[Normal range like '<200 mg/dL']\",
      \"status\": \"[normal/borderline/high]\",
      \"description\": \"[Clear explanation]\"
    }
  ],
  \"recommendations\": [\"[Actionable medical advice]\"],
  \"confidence_score\": [Number 0-100 without % symbol]
}

Medical Report Text:
{$cleanedText}

Extract ALL numerical test results you can find. Return valid JSON only.";

        Log::info("🤖 Sending prompt to DeepSeek", [
            'model' => $model,
            'prompt_length' => strlen($prompt)
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(env('DEEPSEEK_TIMEOUT', 30))->post($baseUrl . '/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical expert AI assistant. Always return valid JSON with actual medical findings.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 2000,
        ]);

        if (!$response->successful()) {
            throw new \Exception('DeepSeek API error: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        Log::info("🤖 DeepSeek raw response", [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 500)
        ]);
        
        return json_decode($content, true);
    }
    
    /**
     * 🔥 NEW: Process with Claude
     */
    private static function processWithClaude($cleanedText)
    {
        if (!env('CLAUDE_ENABLED', true)) {
            throw new \Exception('Claude is disabled in ENV configuration');
        }
        
        $apiKey = env('CLAUDE_API_KEY');
        $baseUrl = env('CLAUDE_BASE_URL', 'https://api.anthropic.com/v1');
        $model = env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022');
        
        if (!$apiKey) {
            throw new \Exception('Claude API key not configured');
        }
        
        $prompt = "You are a medical AI assistant. Analyze this medical report and extract health data in JSON format.

IMPORTANT INSTRUCTIONS:
1. Look for ANY numerical values with units (mg/dL, mmol/L, g/dL, %, etc.)
2. Include ALL test results, even if they appear normal
3. Extract patient demographic information
4. Provide a confidence score above 50% if you find any medical data
5. DO NOT return 'N/A' for key_findings - extract actual test results

Required JSON structure:
{
  \"patient_name\": \"[Full patient name]\",
  \"patient_age\": \"[Age with units]\", 
  \"patient_gender\": \"[male/female/other]\",
  \"diagnosis\": \"[Brief medical summary]\",
  \"key_findings\": [
    {
      \"finding\": \"[Test name like 'Total Cholesterol']\",
      \"value\": \"[Numerical value with unit like '180 mg/dL']\",
      \"reference\": \"[Normal range like '<200 mg/dL']\",
      \"status\": \"[normal/borderline/high]\",
      \"description\": \"[Clear explanation]\"
    }
  ],
  \"recommendations\": [\"[Actionable medical advice]\"],
  \"confidence_score\": [Number 0-100 without % symbol]
}

Medical Report Text:
{$cleanedText}

Extract ALL numerical test results you can find. Return valid JSON only.";

        Log::info("🤖 Sending prompt to Claude", [
            'model' => $model,
            'prompt_length' => strlen($prompt)
        ]);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => env('CLAUDE_ANTHROPIC_VERSION', '2023-06-01'),
            'Content-Type' => 'application/json',
        ])->timeout(env('CLAUDE_TIMEOUT', 30))->post($baseUrl . '/messages', [
            'model' => $model,
            'max_tokens' => 2000,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        
        Log::info("🤖 Claude raw response", [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 500)
        ]);
        
        return json_decode($content, true);
    }
    
    /**
     * Clean text for AI processing (works for all providers)
     */
    private static function cleanTextForAI($text)
    {
        // Step 1: Ensure UTF-8 encoding
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        // Step 2: Remove or replace problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Step 3: Replace common problematic characters
        $text = str_replace(['�', chr(194), chr(162)], ['', '', ''], $text);
        
        // Step 4: Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Step 5: Ensure it's not too long for AI (max ~4000 tokens ≈ 16000 chars)
        if (strlen($text) > 15000) {
            $text = substr($text, 0, 15000) . '...';
        }
        
        // Step 6: Final UTF-8 validation
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Last resort: remove non-ASCII characters
            $text = preg_replace('/[^\x20-\x7E\s]/', '', $text);
        }
        
        return $text;
    }
    
    /**
     * Check if text contains medical terms
     */
    private static function containsMedicalTerms($text)
    {
        $medicalTerms = [
            'cholesterol', 'hdl', 'ldl', 'triglycerides', 'glucose', 'hemoglobin',
            'creatinine', 'urea', 'sodium', 'potassium', 'vitamin', 'thyroid', 'tsh',
            'mg/dl', 'mmol/l', 'g/dl', 'normal', 'elevated', 'low', 'high', 'test',
            'result', 'laboratory', 'lab', 'blood', 'serum', 'plasma', 'report'
        ];
        
        $textLower = strtolower($text);
        $foundTerms = [];
        
        foreach ($medicalTerms as $term) {
            if (strpos($textLower, $term) !== false) {
                $foundTerms[] = $term;
            }
        }
        
        Log::info("🔍 Medical terms analysis", [
            'terms_found' => $foundTerms,
            'medical_terms_count' => count($foundTerms),
            'likely_medical_report' => count($foundTerms) >= 3
        ]);
        
        return count($foundTerms) >= 3;
    }
    
    /**
     * Process key findings to ensure they're meaningful
     */
    private static function processKeyFindings($findings)
    {
        $processedFindings = [];
        
        foreach ($findings as $finding) {
            // Skip N/A findings
            if (is_string($finding) && (strtolower($finding) === 'n/a' || $finding === 'N/A')) {
                Log::warning("🚨 Skipping N/A finding", ['finding' => $finding]);
                continue;
            }
            
            if (is_string($finding)) {
                // Try to extract meaningful information from string findings
                if (preg_match('/(\w+.*?):\s*(\d+\.?\d*)\s*([a-zA-Z\/\%]+)?/', $finding, $matches)) {
                    $processedFindings[] = [
                        'finding' => trim($matches[1]),
                        'value' => $matches[2] . ($matches[3] ?? ''),
                        'reference' => '',
                        'status' => 'normal',
                        'description' => $finding
                    ];
                } else {
                    // Keep as string if it contains meaningful content
                    if (strlen($finding) > 5 && !in_array(strtolower($finding), ['n/a', 'not available', 'none'])) {
                        $processedFindings[] = $finding;
                    }
                }
            } else if (is_array($finding)) {
                // Validate array findings
                if (isset($finding['finding']) && !empty($finding['finding'])) {
                    $processedFindings[] = $finding;
                }
            }
        }
        
        Log::info("🔍 Key findings processing result", [
            'original_count' => count($findings),
            'processed_count' => count($processedFindings),
            'skipped_na_findings' => count($findings) - count($processedFindings)
        ]);
        
        return $processedFindings;
    }
    
    /**
     * Extract patient information
     */
    private static function extractPatientInfo($textSample, $provider = 'openai')
    {
        try {
            $prompt = "Extract ONLY patient information from this medical report. Return JSON with exact keys:
{
  \"patient_name\": \"[Full name or N/A]\",
  \"patient_age\": \"[Age with units or N/A]\", 
  \"patient_gender\": \"[male/female/other or N/A]\"
}

Text: {$textSample}";
            
            if ($provider === 'openai' && env('OPENAI_ENABLED', false)) {
                $client = OpenAI::client(env('OPENAI_API_KEY'));
                $response = $client->chat()->create([
                    'model' => env('OPENAI_MODEL', 'gpt-4'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Extract patient demographics only. Return valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 500,
                ]);
                
                $patientInfo = json_decode($response->choices[0]->message->content, true);
            } else {
                // For other providers or if OpenAI is disabled, return basic extraction
                $patientInfo = [
                    'patient_name' => 'N/A',
                    'patient_age' => 'N/A',
                    'patient_gender' => 'N/A',
                ];
            }
            
            if (!is_array($patientInfo)) {
                return [
                    'patient_name' => 'N/A',
                    'patient_age' => 'N/A',
                    'patient_gender' => 'N/A',
                ];
            }
            
            Log::info("👤 Patient info extracted", $patientInfo);
            
            return $patientInfo;
            
        } catch (\Exception $e) {
            Log::warning("⚠️ Patient info extraction failed", ['error' => $e->getMessage()]);
            return [
                'patient_name' => 'N/A',
                'patient_age' => 'N/A',
                'patient_gender' => 'N/A',
            ];
        }
    }
    
    /**
     * Translate to Hindi (simplified for now)
     */
    private static function translateToHindi($summary, $provider = 'openai')
    {
        try {
            $simplifiedSummary = [
                'diagnosis' => $summary['diagnosis'] ?? 'N/A',
                'key_findings' => array_slice($summary['key_findings'] ?? [], 0, 3),
                'recommendations' => array_slice($summary['recommendations'] ?? [], 0, 3)
            ];
            
            $translatePrompt = "Translate this medical summary to Hindi in simple language:\n\n" . json_encode($simplifiedSummary, JSON_PRETTY_PRINT);
            
            // Only use AI translation if OpenAI is available and enabled
            if ($provider === 'openai' && env('OPENAI_ENABLED', false)) {
                $client = OpenAI::client(env('OPENAI_API_KEY'));
                $translation = $client->chat()->create([
                    'model' => env('OPENAI_MODEL', 'gpt-4'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a Hindi medical translator. Provide simple Hindi translations.'],
                        ['role' => 'user', 'content' => $translatePrompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 1000,
                ]);
                
                return $translation->choices[0]->message->content;
            } else {
                // For other providers, return a simple message
                return 'हिंदी अनुवाद उपलब्ध नहीं है (Hindi translation not available)';
            }
            
        } catch (\Exception $e) {
            Log::warning("⚠️ Hindi translation failed", ['error' => $e->getMessage()]);
            return 'Translation not available';
        }
    }
    
    /**
     * 🔥 UPDATED: Generate detailed finding information - Claude-first approach
     */
    public static function generateFindingDetails($finding, $context = '')
    {
        try {
            Log::info("🔍 Generating finding details with Claude-first approach", [
                'finding' => is_array($finding) ? $finding['finding'] : $finding,
                'claude_enabled' => env('CLAUDE_ENABLED', true),
                'openai_enabled' => env('OPENAI_ENABLED', false)
            ]);
            
            $findingName = is_array($finding) ? $finding['finding'] : $finding;
            $findingValue = is_array($finding) ? $finding['value'] : '';
            $findingReference = is_array($finding) ? $finding['reference'] : '';
            $findingStatus = is_array($finding) ? $finding['status'] : 'high';
            $findingDescription = is_array($finding) ? $finding['description'] : $finding;
            
            $prompt = "You are a caring, experienced doctor explaining medical test results to a patient who may not understand medical terminology. Your goal is to make complex medical information easy to understand while being accurate and reassuring.

            PATIENT'S TEST RESULT:
            - Test Name: {$findingName}
            - Patient's Value: {$findingValue}
            - Normal Range: {$findingReference}
            - Status: {$findingStatus} (" . ($findingStatus === 'borderline' ? 'slightly outside normal range' : ($findingStatus === 'high' ? 'significantly above normal' : 'significantly below normal')) . ")
            - Medical Description: {$findingDescription}
            
            Please provide detailed, evidence-based information that helps the patient understand what this means for their health. Use simple language that anyone can understand, avoid medical jargon, and be reassuring while being honest.
            
            Return your response in this exact JSON format:
            
            {
              \"cases\": [
                {\"title\": \"Most Common Reason (60-70%)\", \"description\": \"Explain in simple terms why this usually happens, using everyday language. Include approximate percentages when known from medical literature.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 1},
                {\"title\": \"Second Common Cause (20-30%)\", \"description\": \"Another frequent reason, explained simply with real-world examples the patient can relate to.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 2},
                {\"title\": \"Less Common But Important\", \"description\": \"Other possible causes worth knowing about, explained without causing unnecessary worry.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 3}
              ],
              \"symptoms\": [
                {\"title\": \"What You Might Notice\", \"description\": \"Physical signs or feelings the patient might experience, described in terms they can easily recognize in their daily life.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 1},
                {\"title\": \"Other Possible Signs\", \"description\": \"Additional symptoms to be aware of, explained as things they might feel or observe about themselves.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 2},
                {\"title\": \"When to Be Concerned\", \"description\": \"Warning signs that mean they should contact their doctor soon, described clearly and calmly.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 3}
              ],
              \"remedies\": [
                {\"title\": \"Simple Changes You Can Make\", \"description\": \"Practical, easy-to-follow lifestyle modifications that can help, with specific examples (like 'walk 20 minutes daily' instead of 'increase physical activity').\", \"icon\": \"[choose relevant emoji]\", \"priority\": 1},
                {\"title\": \"Foods That Help\", \"description\": \"Specific foods to eat more of and foods to avoid, explained in practical terms with examples of meals or snacks.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 2},
                {\"title\": \"Medical Treatment Options\", \"description\": \"What doctors might recommend for treatment, explained in simple terms without frightening medical terminology.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 3}
              ],
              \"consequences\": [
                {\"title\": \"If Not Addressed Soon\", \"description\": \"What could happen in the next few months if this isn't taken care of, explained honestly but not alarmingly.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 1},
                {\"title\": \"Long-term Health Impact\", \"description\": \"Potential effects on their health over years if left untreated, balanced with reassurance that these can often be prevented.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 2},
                {\"title\": \"The Good News\", \"description\": \"Positive aspects - how treatable this condition is, success rates, or how small changes can make big differences.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 3}
              ],
              \"next_steps\": [
                {\"title\": \"What to Do Right Now\", \"description\": \"Immediate actions they can take today, explained step-by-step in simple terms.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 1},
                {\"title\": \"Talk to Your Doctor About\", \"description\": \"Specific questions to ask their doctor and what information to bring to the appointment.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 2},
                {\"title\": \"When to Retest\", \"description\": \"Specific timeframe for follow-up testing (e.g., 'in 3 months' or 'in 6 weeks') and why retesting at that time is important.\", \"icon\": \"[choose relevant emoji]\", \"priority\": 3}
              ]
            }
            
            IMPORTANT GUIDELINES:
            - Use warm, empathetic language as if speaking to a worried family member
            - Choose emojis that genuinely relate to the content (not generic medical symbols)
            - Include specific percentages, timeframes, and numbers when available from medical literature
            - Explain technical terms in parentheses: 'inflammation (swelling and irritation)'
            - Focus on what the patient CAN do, not just what's wrong
            - Consider the severity level: '{$findingStatus}' findings need different urgency levels
            - Provide hope and reassurance while being medically accurate
            - Use everyday examples: 'like a garden hose with high water pressure' for blood pressure
            - Provide 2-4 items per category for comprehensive coverage
            - Use medical expertise to give accurate, helpful information
            
            Additional Context: {$context}
            
            Remember: This person is likely worried about their health. Your explanation should inform, reassure when appropriate, and empower them to take positive action.
            
            CRITICAL: Return valid JSON only - no additional text, explanations, or commentary outside the JSON structure.";
            
            // 🎯 PRIORITY 1: Try Claude first (best for medical explanations)
            if (env('CLAUDE_ENABLED', true)) {
                try {
                    Log::info("🧠 Using Claude for finding details (Priority 1)");
                    $details = self::callClaudeForFindings($prompt);
                    
                    if (is_array($details) && self::validateFindingDetailsResponse($details)) {
                        Log::info("✅ Claude finding details successful");
                        return $details;
                    } else {
                        throw new \Exception('Claude returned invalid response format');
                    }
                } catch (\Exception $e) {
                    Log::warning("⚠️ Claude failed for finding details", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 🎯 PRIORITY 2: Try OpenAI as fallback (if enabled)
            if (env('OPENAI_ENABLED', false)) {
                try {
                    Log::info("🤖 Using OpenAI for finding details (Fallback)");
                    $details = self::callOpenAIForFindings($prompt);
                    
                    if (is_array($details) && self::validateFindingDetailsResponse($details)) {
                        Log::info("✅ OpenAI finding details successful");
                        return $details;
                    } else {
                        throw new \Exception('OpenAI returned invalid response format');
                    }
                } catch (\Exception $e) {
                    Log::warning("⚠️ OpenAI failed for finding details", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // No DeepSeek option - Claude and OpenAI are better for detailed medical explanations
            
            throw new \Exception('All available providers failed for finding details');
            
        } catch (\Exception $e) {
            Log::error("❌ All providers failed for finding details", [
                'error' => $e->getMessage(),
                'finding' => is_array($finding) ? $finding['finding'] : $finding
            ]);
            
            // Return user-friendly fallback
            return self::getFallbackFindingDetails($findingName ?? 'Unknown Finding');
        }
    }
    
    /**
     * Validate finding details response structure
     */
    private static function validateFindingDetailsResponse($details): bool
    {
        $requiredKeys = ['cases', 'symptoms', 'remedies', 'consequences', 'next_steps'];
        
        foreach ($requiredKeys as $key) {
            if (!isset($details[$key]) || !is_array($details[$key])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get fallback finding details when all providers fail
     */
    private static function getFallbackFindingDetails($findingName): array
    {
        return [
            'cases' => [
                ['title' => 'Medical Consultation Recommended', 'description' => "For detailed information about {$findingName}, please consult with your healthcare provider.", 'icon' => '👨‍⚕️', 'priority' => 1],
                ['title' => 'Laboratory Analysis', 'description' => 'This finding requires professional medical interpretation in context of your overall health.', 'icon' => '🔬', 'priority' => 2]
            ],
            'symptoms' => [
                ['title' => 'Professional Assessment Needed', 'description' => 'Symptoms related to this finding should be discussed with a medical professional.', 'icon' => '🩺', 'priority' => 1],
                ['title' => 'Monitor Changes', 'description' => 'Keep track of any new symptoms or changes in your condition.', 'icon' => '📋', 'priority' => 2]
            ],
            'remedies' => [
                ['title' => 'Consult Healthcare Provider', 'description' => 'Treatment options should be discussed with your doctor based on your individual case.', 'icon' => '💊', 'priority' => 1],
                ['title' => 'Follow Medical Advice', 'description' => 'Adhere to any existing treatment plans and medication schedules.', 'icon' => '📝', 'priority' => 2]
            ],
            'consequences' => [
                ['title' => 'Individual Assessment Required', 'description' => 'The significance of this finding varies based on individual health factors.', 'icon' => '⚠️', 'priority' => 1],
                ['title' => 'Regular Monitoring', 'description' => 'Regular check-ups help ensure proper management of this finding.', 'icon' => '📊', 'priority' => 2]
            ],
            'next_steps' => [
                ['title' => 'Schedule Appointment', 'description' => 'Book a consultation with your healthcare provider to discuss this finding.', 'icon' => '📅', 'priority' => 1],
                ['title' => 'Prepare Questions', 'description' => 'Write down any questions or concerns to discuss during your appointment.', 'icon' => '❓', 'priority' => 2]
            ]
        ];
    }
    
    /**
     * Call Claude for finding details (optimized for medical explanations)
     */
    private static function callClaudeForFindings($prompt)
    {
        $response = Http::withHeaders([
            'x-api-key' => env('CLAUDE_API_KEY'),
            'anthropic-version' => env('CLAUDE_ANTHROPIC_VERSION', '2023-06-01'),
            'Content-Type' => 'application/json',
        ])->timeout(env('CLAUDE_TIMEOUT', 30))->post(env('CLAUDE_BASE_URL', 'https://api.anthropic.com/v1') . '/messages', [
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => 2000, // Increased for detailed medical explanations
            'temperature' => 0.1,
            'messages' => [
                [
                    'role' => 'user', 
                    'content' => "You are a medical expert providing detailed, accurate health information. Focus on practical, actionable advice. " . $prompt
                ],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Claude API error: ' . $response->status() . ' - ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        
        // Clean up Claude's response (remove code blocks if present)
        $content = preg_replace('/^```(?:json)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        
        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse Claude JSON response: ' . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Call OpenAI for finding details (fallback option)
     */
    private static function callOpenAIForFindings($prompt)
    {
        $client = OpenAI::client(env('OPENAI_API_KEY'));
        $response = $client->chat()->create([
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical expert providing detailed, accurate health information. Return valid JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 2000, // Increased for detailed explanations
        ]);
        
        $content = $response->choices[0]->message->content;
        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse OpenAI JSON response: ' . json_last_error_msg());
        }
        
        return $decoded;
    }
}