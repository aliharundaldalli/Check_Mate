-- AI Analysis Report için gerekli veritabanı güncellemesi
-- Bu dosyayı phpMyAdmin'de çalıştırın

-- quizzes tablosuna ai_analysis kolonu ekle
ALTER TABLE `quizzes` 
ADD COLUMN `ai_analysis` TEXT NULL 
COMMENT 'AI tarafından oluşturulan sınıf analiz raporu (JSON formatında)' 
AFTER `description`;

-- Başarılı mesajı
SELECT 'ai_analysis kolonu başarıyla eklendi!' AS Status;
