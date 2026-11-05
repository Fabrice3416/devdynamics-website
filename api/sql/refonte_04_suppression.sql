-- ============================================================
-- Refonte du site — suppression des anciennes tables. IRRÉVERSIBLE.
-- À exécuter seulement après la mise en ligne, la recette et l'accord sur cette
-- liste, avec une sauvegarde complète vérifiée.
-- Noms explicites, dans l'ordre imposé par les clés étrangères.
-- Restent : users, contact_messages, organization_info, founders,
-- attendance_sheet, kkp_responses et les tables de la refonte.
-- ============================================================

DROP TABLE IF EXISTS quiz_attempt_answers;
DROP TABLE IF EXISTS quiz_attempts;
DROP TABLE IF EXISTS quiz_answers;
DROP TABLE IF EXISTS quiz_questions;
DROP TABLE IF EXISTS module_quizzes;
DROP TABLE IF EXISTS module_completion;
DROP TABLE IF EXISTS student_progress;
DROP TABLE IF EXISTS course_lessons;
DROP TABLE IF EXISTS course_modules;
DROP TABLE IF EXISTS course_final_quizzes;
DROP TABLE IF EXISTS certificates;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS course_enrollments;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS blog_posts;
DROP TABLE IF EXISTS donations;
DROP TABLE IF EXISTS testimonials;
DROP TABLE IF EXISTS sponsors;
DROP TABLE IF EXISTS email_verification_tokens;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS acp_candidatures;
DROP TABLE IF EXISTS acp_counters;
DROP TABLE IF EXISTS acp_fiches_counters;
DROP TABLE IF EXISTS acp_fiches_etude;

-- Aucun compte étudiant ne reste (vérifié le 05/11/2026) : le rôle disparaît avec les cours.
-- À revérifier avant de lancer : SELECT role, COUNT(*) FROM users GROUP BY role;
ALTER TABLE users MODIFY role ENUM('admin','editor') DEFAULT 'editor';
