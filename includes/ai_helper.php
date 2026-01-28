<?php
/**
 * Check Mate - AI Helper
 * Google Gemini API integration for quiz generation and grading
 */

function gradeQuizSubmission($quiz, $questions, $userAnswers) {
    // 1. API Key Setup
    $geminiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''));
    
    // Provider Belirleme (Currently only Google Gemini supported in Check Mate)
    $apiKey = $geminiKey;

    // ======================================================
    // 2. HİBRİT DEĞERLENDİRME (LOCAL vs AI)
    // ======================================================
    
    $totalScore = 0;
    $details = [];
    $questionsForAi = [];
    
    foreach ($questions as $q) {
        $q_id = $q['id'];
        $user_ans_raw = $userAnswers[$q_id] ?? ''; // JSON string or text
        $points = $q['points'];
        
        // Cevabı parse et
        $user_ans_val = $user_ans_raw;
        
        // EĞER SORU AI PUANLAMASI İSTENMİŞSE veya KESİN CEVAP YOKSA AI'a GİTSİN
        // (type=text/textarea) VEYA (is_ai_graded=1)
        if (($q['question_type'] == 'text' || $q['question_type'] == 'textarea') || !empty($q['is_ai_graded'])) {
             $questionsForAi[] = $q;
             continue;
        }

        // --- LOCAL GRADING (Çoktan Seçmeli) ---
        $earned = 0;
        $feedback = '';
        
        $correct_raw = $q['correct_answer']; // Veritabanındaki doğru cevap

        if ($q['question_type'] == 'multiple_choice') {
            // Basit string karşılaştırma
            $cleanUser = trim(strip_tags($user_ans_raw));
            $cleanCorrect = trim(strip_tags($correct_raw));
            
            if (strcasecmp($cleanUser, $cleanCorrect) === 0) {
                $earned = $points;
                $feedback = "Doğru cevap.";
            } else {
                $feedback = "Yanlış cevap. Doğrusu: $cleanCorrect";
            }
        } 
        elseif ($q['question_type'] == 'multiple_select') {
            // Array karşılaştırma
            $uArr = is_array($user_ans_raw) ? $user_ans_raw : json_decode($user_ans_raw, true);
            $cArr = json_decode($correct_raw, true);
            
            if (!is_array($uArr)) $uArr = [];
            if (!is_array($cArr)) $cArr = []; 
            
            $totalCorrectOptions = count($cArr);
            
            if ($totalCorrectOptions == 0) {
                $earned = 0;
                $feedback = "Cevap anahtarı eksik.";
            } else {
                $unitValue = $points / $totalCorrectOptions;
                
                $matches = array_intersect($uArr, $cArr);
                $matchCount = count($matches);
                
                $wrongs = array_diff($uArr, $cArr);
                $wrongCount = count($wrongs);
                
                $netCorrect = max(0, $matchCount - $wrongCount);
                $earned = round($netCorrect * $unitValue, 2);
                
                if ($matchCount == $totalCorrectOptions && $wrongCount == 0) {
                    $feedback = "Tamamen doğru.";
                } else {
                    $feedback = sprintf(
                        "%d doğru, %d yanlış seçim. Toplam: %s Puan",
                        $matchCount, $wrongCount, number_format($earned, 2)
                    );
                }
            }
        } else {
            // Bilinmeyen tip (AI'a yolla)
             $questionsForAi[] = $q;
             continue;
        }

        // Local sonucu kaydet
        $totalScore += $earned;
        $details[$q_id] = [
            'id' => $q_id,
            'earned_points' => $earned,
            'feedback' => $feedback,
            'is_correct' => ($earned == $points) // Basit mantık
        ];
    }
    
    // ======================================================
    // 3. AI DEĞERLENDİRMESİ (SADECE GEREKLİ SORULAR)
    // ======================================================
    
    if (empty($questionsForAi)) {
        return [
            'score' => $totalScore,
            'feedback' => 'Otomatik değerlendirme tamamlandı.',
            'details' => $details
        ];
    }
    
    if (!$apiKey) {
         foreach ($questionsForAi as $q) {
             $details[$q['id']] = ['id' => $q['id'], 'earned_points' => 0, 'feedback' => 'AI anahtarı eksik, değerlendirilemedi.'];
         }
         return [
            'score' => $totalScore,
            'feedback' => 'AI servisi kullanılamadı.',
            'details' => $details
        ];
    }

    // AI PROMPT HAZIRLIĞI
    $systemContent = "Sen 'Check Mate' eğitim platformunda görevli, yardımsever ve motive edici bir öğretmensin.";
    $systemContent .= " Görevin öğrencilerin sınav cevaplarını değerlendirmek. Not verirken adil ol, ancak her zaman yapıcı ve destekleyici bir dil kullan.";
    $systemContent .= " Eğer öğrenci tam puan alamadıysa, doğrusunu nazikçe açıkla ve onları çalışmaya teşvik et. Asla kırıcı veya sadece 'Yanlış' diyen kısa cevaplar verme.";
    $systemContent .= " Özellikle açık uçlu sorularda, öğrencinin cevabındaki kısmen doğru noktaları da takdir et.";
    $systemContent .= "\n\n--- ÇIKTI FORMATI ---\n";
    $systemContent .= "Yanıtını SADECE geçerli bir JSON formatında ver.\n";
    $systemContent .= "{\n";
    $systemContent .= '  "general_feedback": "Öğrenciye genel motivasyon notu (örn: Tebrikler, harika iş! veya Daha iyisini yapabilirsin, gayretini takdir ediyorum.)",' . "\n";
    $systemContent .= '  "questions": [' . "\n";
    $systemContent .= '    { "id": (Soru ID), "earned_points": (Puan - int), "feedback": "Kısa yorum" }' . "\n";
    $systemContent .= '  ]' . "\n";
    $systemContent .= "}";

    $userContent = "Aşağıdaki soruları değerlendir:\n";
    
    foreach ($questionsForAi as $q) {
        $q_id = $q['id'];
        $answer = isset($userAnswers[$q_id]) ? $userAnswers[$q_id] : '(Boş Bırakıldı)';
        $cleanAnswer = str_replace(["\r", "\n"], " ", strip_tags($answer));
        
        $userContent .= "SORU ID: " . $q_id . "\n";
        $userContent .= "Soru: " . $q['question_text'] . "\n";
        $userContent .= "Max Puan: " . $q['points'] . "\n";
        if (!empty($q['ai_grading_prompt'])) {
            $userContent .= "Kriter: " . $q['ai_grading_prompt'] . "\n";
        }
        $userContent .= "Cevap: " . $cleanAnswer . "\n";
        $userContent .= "-------------------\n";
    }

    // Google Gemini API Call
    $model = "gemini-flash-latest"; 
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
    $data = [
        "systemInstruction" => ["parts" => [["text" => $systemContent]]],
        "contents" => [["role" => "user", "parts" => [["text" => $userContent]]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.5]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    // Localhost SSL fix
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return ['error' => 'Curl error: ' . curl_error($ch), 'score' => $totalScore, 'details' => $details];
    }
    curl_close($ch);

    // Process AI Result
    $result = json_decode($response, true);
    $rawText = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if ($rawText) {
        $cleanJson = preg_replace('/^```json\s*|```\s*$/m', '', $rawText);
        $cleanJson = trim($cleanJson);
        $decoded = json_decode($cleanJson, true);

        if ($decoded && isset($decoded['questions'])) {
            foreach($decoded['questions'] as $q_res) {
                $qid = (int)$q_res['id'];
                $earned = (int)($q_res['earned_points'] ?? 0);
                
                $totalScore += $earned;
                $details[$qid] = [
                    'id' => $qid,
                    'earned_points' => $earned,
                    'feedback' => $q_res['feedback'] ?? '',
                    'is_correct' => ($earned > 0)
                ];
            }
            
            return [
                'score' => $totalScore,
                'feedback' => $decoded['general_feedback'] ?? 'Değerlendirme tamamlandı.',
                'details' => $details
            ];
        }
    }
    
    return ['error' => 'AI yanıtı işlenemedi', 'score' => $totalScore, 'details' => $details];
}

/**
 * Generate Quiz Questions via AI
 */
function generateQuizQuestions($topic, $difficulty, $count, $type = 'mixed') {
    $geminiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''));
    
    if (!$geminiKey) return ['error' => 'API Key not found. Lütfen .env dosyasını kontrol edin.'];

    $prompt = "Konu: $topic\n";
    $prompt .= "Zorluk Seviyesi: $difficulty\n";
    $prompt .= "Soru Sayısı: $count\n";
    $prompt .= "Soru Tipi Tercihi: $type (mixed ise karışık, multiple_choice ise hepsi çoktan seçmeli, multiple_select ise çoktan çok seçmeli)\n\n";
    $prompt .= "Lütfen yukarıdaki kriterlere uygun sınav soruları oluştur. Çıktı SADECE aşağıdaki formatta geçerli bir JSON dizisi olmalıdır.\n";
    $prompt .= "Örnek JSON Formatları:\n";
    $prompt .= "1. Çoktan Seçmeli: { \"type\": \"multiple_choice\", \"text\": \"Soru...\", \"options\": [\"A) x\", \"B) y\"], \"correct_answer\": \"A) x\", \"points\": 10 }\n";
    $prompt .= "2. Çoktan ÇOK Seçmeli: { \"type\": \"multiple_select\", \"text\": \"Soru...\", \"options\": [\"A) x\", \"B) y\", \"C) z\"], \"correct_answer\": [\"A) x\", \"C) z\"], \"points\": 10 } (Dikkat: correct_answer JSON array olmalı)\n";
    $prompt .= "3. Klasik: { \"type\": \"textarea\", \"text\": \"Soru...\", \"options\": null, \"correct_answer\": \"Cevap anahtarı\", \"ai_prompt\": \"...\", \"points\": 10 }\n\n";
    $prompt .= "ÇIKTI FORMATI:\n";
    $prompt .= "[\n";
    $prompt .= "  {\n";
    $prompt .= "    \"text\": \"Soru metni\",\n";
    $prompt .= "    \"type\": \"multiple_choice\" veya \"text\" veya \"textarea\" veya \"multiple_select\",\n";
    $prompt .= "    \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"] (multiple_choice ve multiple_select için zorunlu, diğerleri için null),\n";
    $prompt .= "    \"correct_answer\": \"Doğru seçenek metni\" (multiple_select için JSON formatında array örn: [\"A) ...\", \"C) ...\"]),\n";
    $prompt .= "    \"points\": 10,\n";
    $prompt .= "    \"ai_prompt\": \"Puanlama kriteri (açık uçlu sorular için)\"\n";
    $prompt .= "  }\n";
    $prompt .= "]\n";

    $data = [
        "systemInstruction" => ["parts" => [["text" => "Sen profesyonel bir sınav hazırlama uzmanısın. Türk eğitim sistemine uygun sorular hazırla."]]],
        "contents" => [["role" => "user", "parts" => [["text" => $prompt]]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.7]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $geminiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) return ['error' => curl_error($ch)];
    curl_close($ch);

    $result = json_decode($response, true);
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $raw = $result['candidates'][0]['content']['parts'][0]['text'];
        $clean = preg_replace('/^```json\s*|```\s*$/m', '', $raw);
        return json_decode($clean, true);
    }

    return ['error' => 'AI yanıtı alınamadı.', 'debug' => $response];
}

/**
 * Generate Overall Quiz Analysis via AI
 */
function generateQuizOverviewAnalysis($quiz, $questions, $submissions, $allAnswers) {
    $geminiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''));
    if (!$geminiKey) return ['error' => 'API Key eksik.'];

    // 1. İstatistikleri Hazırla
    $totalStudents = count($submissions);
    if ($totalStudents == 0) return ['error' => 'Analiz için yeterli veri yok (Öğrenci sayısı: 0).'];

    $scores = array_column($submissions, 'score');
    $avgScore = array_sum($scores) / $totalStudents;
    $maxScore = max($scores);
    $minScore = min($scores);
    
    // Geçme/Kalma (Varsayılan 50)
    $passCount = count(array_filter($scores, fn($s) => $s >= 50));
    $passRate = ($passCount / $totalStudents) * 100;

    // Soru Bazlı Analiz (En zor soruları bul)
    $questionStats = [];
    foreach ($questions as $q) {
        $q_id = $q['id'];
        $earnedTotal = 0;
        $attemptCount = 0;
        
        foreach ($allAnswers as $ans) {
            if ($ans['question_id'] == $q_id) {
                $earnedTotal += $ans['earned_points'];
                $attemptCount++;
            }
        }
        
        $avgPts = $attemptCount > 0 ? ($earnedTotal / $attemptCount) : 0;
        $successRate = ($q['points'] > 0) ? ($avgPts / $q['points']) * 100 : 0;
        
        $questionStats[$q_id] = [
            'text' => $q['question_text'], // Kısaltılabilir
            'success_rate' => $successRate
        ];
    }
    
    // Başarı oranına göre sırala (Artan - En zorlar başta)
    uasort($questionStats, fn($a, $b) => $a['success_rate'] <=> $b['success_rate']);
    $hardestQuestions = array_slice($questionStats, 0, 3); // İlk 3 zor soru

    // 2. AI Prompt Oluştur
    $prompt = "Sen bir eğitim veri analistisin. Aşağıdaki sınav sonuçlarını analiz ederek öğretmen için genel bir değerlendirme raporu hazırla.\n\n";
    $prompt .= "Sınav: " . $quiz['title'] . "\n";
    $prompt .= "Ders Kodu: " . $quiz['course_code'] . "\n";
    $prompt .= "--- İstatistikler ---\n";
    $prompt .= "Katılımcı Sayısı: $totalStudents\n";
    $prompt .= "Ortalama Puan: " . number_format($avgScore, 1) . "\n";
    $prompt .= "En Yüksek: $maxScore, En Düşük: $minScore\n";
    $prompt .= "Başarı Oranı (>=50): %" . number_format($passRate, 1) . "\n";
    $prompt .= "\n--- En Zorlanılan Sorular (Düşük Başarı) ---\n";
    foreach ($hardestQuestions as $qid => $qs) {
        $prompt .= "- Soru: " . mb_strimwidth($qs['text'], 0, 100, '...') . " (Başarı: %" . number_format($qs['success_rate'], 1) . ")\n";
    }
    
    $prompt .= "\n--- İstenen Çıktı (JSON) ---\n";
    $prompt .= "Lütfen şu JSON formatında yanıt ver:\n";
    $prompt .= "{\n";
    $prompt .= "  \"general_summary\": \"Sınıfın genel durumu hakkında kısa özet (tek paragraf).\",\n";
    $prompt .= "  \"strengths\": \"Sınıfın iyi olduğu yönler.\",\n";
    $prompt .= "  \"weaknesses\": \"Geliştirilmesi gereken alanlar ve zorlanılan konular.\",\n";
    $prompt .= "  \"recommendations\": \"Öğretmen için öneriler (örn: Şu konuyu tekrar anlatın).\"\n";
    $prompt .= "}\n";

    // 3. API Çağrısı
    $data = [
        "systemInstruction" => ["parts" => [["text" => "Sen profesyonel bir eğitim danışmanısın. Analizlerin veri odaklı ve yapıcı olmalı."]]],
        "contents" => [["role" => "user", "parts" => [["text" => $prompt]]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.5]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $geminiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) return ['error' => curl_error($ch)];
    curl_close($ch);

    $result = json_decode($response, true);
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $raw = $result['candidates'][0]['content']['parts'][0]['text'];
        $clean = preg_replace('/^```json\s*|```\s*$/m', '', $raw);
        $json = json_decode($clean, true);
        if ($json) return $json;
    }

    return ['error' => 'AI Analizi oluşturulamadı.'];
}
?>
