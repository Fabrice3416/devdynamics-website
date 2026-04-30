// ============================================
// COURSE CONTENT VIEWER - Student Course Player
// ============================================

let currentUser = null;
let currentCourse = null;
let currentCourseId = null;
let allModules = [];
let currentLessonId = null;
let currentModuleId = null;
let enrollment = null;
let courseProgress = null;
let completedLessonIds = [];
let completedModuleIds = [];
let unlockedModules = {}; // Track which modules are unlocked
let finalQuiz = null; // Final quiz for the course
let finalQuizAttempt = null; // Current attempt for final quiz

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', async () => {
  // Check authentication
  const token = localStorage.getItem('auth_token');
  const userData = localStorage.getItem('user_data');

  if (!token || !userData) {
    window.location.href = 'student-login.html';
    return;
  }

  currentUser = JSON.parse(userData);

  // Get courseId from URL
  const urlParams = new URLSearchParams(window.location.search);
  currentCourseId = urlParams.get('id');

  if (!currentCourseId) {
    showNotification('ID de cours manquant', 'error');
    window.location.href = 'student-dashboard.html';
    return;
  }

  // Initialize mobile menu
  initMobileMenu();

  // Load course and verify enrollment
  await loadCourse();
  await verifyEnrollment();
  await loadCourseProgress();
  await loadModulesAndLessons();
  await loadFinalQuiz();
  setupEventListeners();
});

// ============================================
// LOAD COURSE DETAILS
// ============================================

async function loadCourse() {
  try {
    const response = await api.getCourse(currentCourseId);
    if (response.success) {
      currentCourse = response.data;
      document.getElementById('course-title').textContent = currentCourse.title;
      document.getElementById('course-subtitle').textContent = currentCourse.description || '';
      document.title = `${currentCourse.title} - DevDynamics`;
    }
  } catch (error) {
    console.error('Erreur lors du chargement du cours:', error);
    showNotification('Erreur lors du chargement du cours', 'error');
    setTimeout(() => {
      window.location.href = 'student-dashboard.html';
    }, 2000);
  }
}

// ============================================
// VERIFY ENROLLMENT AND ACCESS
// ============================================

async function verifyEnrollment() {
  try {
    const response = await api.getStudentEnrollments();
    if (response.success) {
      const enrollments = response.data;
      enrollment = enrollments.find(e => e.course_id == currentCourseId);

      if (!enrollment) {
        showNotification('Vous n\'êtes pas inscrit à ce cours', 'error');
        setTimeout(() => {
          window.location.href = 'course-details.html?id=' + currentCourseId;
        }, 2000);
        return;
      }

      if (!enrollment.access_granted || enrollment.status !== 'approved') {
        showNotification('Accès refusé. Veuillez vérifier votre inscription.', 'error');
        setTimeout(() => {
          window.location.href = 'student-dashboard.html';
        }, 2000);
        return;
      }

      // Update progress display
      updateProgressDisplay(enrollment.progress_percentage || 0);
    }
  } catch (error) {
    console.error('Erreur lors de la vérification de l\'inscription:', error);
    showNotification('Erreur lors de la vérification de l\'accès', 'error');
  }
}

// ============================================
// LOAD COURSE PROGRESS
// ============================================

async function loadCourseProgress() {
  try {
    const response = await api.getCourseProgress(currentCourseId);
    if (response.success) {
      courseProgress = response.data;
      completedLessonIds = courseProgress.completed_lesson_ids || [];
      completedModuleIds = courseProgress.completed_module_ids || [];
      updateProgressDisplay(courseProgress.progress_percentage || 0);
    }
  } catch (error) {
    console.error('Erreur lors du chargement de la progression:', error);
  }
}

// ============================================
// LOAD MODULES AND LESSONS
// ============================================

async function loadModulesAndLessons() {
  try {
    const response = await api.getCourseModules(currentCourseId);
    if (response.success) {
      allModules = response.data || [];

      // Load lessons for each module and check if module is unlocked
      for (const module of allModules) {
        const lessonsResponse = await api.getModuleLessons(module.id);
        module.lessons = lessonsResponse.data || [];

        // Check if module is unlocked
        const unlockedResponse = await api.checkModuleUnlocked(module.id);
        unlockedModules[module.id] = unlockedResponse.success && unlockedResponse.data.unlocked;
      }

      renderModulesNav();
    }
  } catch (error) {
    console.error('Erreur lors du chargement des chapitres:', error);
    showNotification('Erreur lors du chargement du contenu', 'error');
  }
}

function renderModulesNav() {
  const container = document.getElementById('modules-nav');

  if (allModules.length === 0) {
    container.innerHTML = '<p class="empty-state">Aucun chapitre disponible pour ce cours</p>';
    return;
  }

  container.innerHTML = allModules.map(module => {
    const publishedLessons = module.lessons.filter(l => l.is_published);
    const isUnlocked = unlockedModules[module.id];
    const isCompleted = completedModuleIds.includes(module.id);
    const lockIcon = isUnlocked ? '' : '🔒 ';
    const completedIcon = isCompleted ? '✅ ' : '';

    return `
      <div class="module-item ${!isUnlocked ? 'locked' : ''} ${isCompleted ? 'completed' : ''}" data-module-id="${module.id}">
        <div class="module-header" onclick="toggleModule(${module.id})">
          <span class="module-toggle">▶</span>
          <div class="module-info">
            <h3 class="module-title">${lockIcon}${completedIcon}Chapitre ${module.order_position}: ${module.title}</h3>
            <p class="module-meta">${publishedLessons.length} leçon${publishedLessons.length > 1 ? 's' : ''}</p>
          </div>
        </div>
        <div class="lessons-list" id="lessons-${module.id}" style="display: none;">
          ${!isUnlocked
            ? '<p class="empty-lessons">🔒 Ce chapitre est verrouillé. Complétez le chapitre précédent et réussissez le quiz pour y accéder.</p>'
            : publishedLessons.length > 0
              ? publishedLessons.map(lesson => `
                <div class="lesson-item ${currentLessonId === lesson.id ? 'active' : ''}"
                     data-lesson-id="${lesson.id}"
                     onclick="loadLesson(${lesson.id}, ${module.id})">
                  <span class="lesson-number">${lesson.order_position}</span>
                  <div class="lesson-info">
                    <h4 class="lesson-title-nav">${lesson.title}</h4>
                    ${lesson.duration_minutes ? `<span class="lesson-duration">⏱️ ${lesson.duration_minutes} min</span>` : ''}
                  </div>
                  <span class="lesson-status">${completedLessonIds.includes(lesson.id) ? '✅' : '⚪'}</span>
                </div>
              `).join('') + `
              <div class="module-quiz-link" onclick="showModuleQuiz(${module.id})">
                <span class="quiz-icon">📝</span>
                <div class="quiz-info">
                  <h4>Quiz du chapitre</h4>
                  <p>${isCompleted ? 'Quiz réussi ✅' : 'Terminez toutes les leçons puis passez le quiz'}</p>
                </div>
              </div>`
              : '<p class="empty-lessons">Aucune leçon publiée</p>'}
        </div>
      </div>
    `;
  }).join('');
}

// ============================================
// MODULE TOGGLE
// ============================================

window.toggleModule = function(moduleId) {
  const lessonsContainer = document.getElementById(`lessons-${moduleId}`);
  const moduleItem = document.querySelector(`[data-module-id="${moduleId}"]`);
  const toggle = moduleItem.querySelector('.module-toggle');

  if (lessonsContainer.style.display === 'none') {
    lessonsContainer.style.display = 'block';
    toggle.textContent = '▼';
  } else {
    lessonsContainer.style.display = 'none';
    toggle.textContent = '▶';
  }
};

// ============================================
// LOAD LESSON CONTENT
// ============================================

window.loadLesson = async function(lessonId, moduleId) {
  try {
    currentLessonId = lessonId;
    currentModuleId = moduleId;

    // Update active state
    document.querySelectorAll('.lesson-item').forEach(item => {
      item.classList.remove('active');
    });
    document.querySelector(`[data-lesson-id="${lessonId}"]`)?.classList.add('active');

    // Show lesson section, hide welcome
    document.getElementById('welcome-section').classList.remove('active');
    document.getElementById('lesson-section').classList.add('active');
    document.getElementById('quiz-section').classList.remove('active');

    // Load lesson data
    const response = await api.getLesson(lessonId);
    if (response.success) {
      const lesson = response.data;
      displayLesson(lesson);
      updateNavigationButtons();
    }
  } catch (error) {
    console.error('Erreur lors du chargement de la leçon:', error);
    showNotification('Erreur lors du chargement de la leçon', 'error');
  }
};

function displayLesson(lesson) {
  document.getElementById('lesson-title').textContent = lesson.title;

  // Video player
  const videoContainer = document.getElementById('video-container');
  const videoPlayer = document.getElementById('video-player');

  if (lesson.video_url) {
    videoContainer.style.display = 'block';
    videoPlayer.innerHTML = createVideoEmbed(lesson.video_url);
  } else {
    videoContainer.style.display = 'none';
  }

  // Lesson content (Markdown would be rendered here with a library like marked.js)
  const contentContainer = document.getElementById('lesson-content');
  if (lesson.content) {
    // For now, just display as plain text with line breaks
    contentContainer.innerHTML = `<div class="lesson-text">${lesson.content.replace(/\n/g, '<br>')}</div>`;
  } else {
    contentContainer.innerHTML = '<p class="empty-state">Aucun contenu pour cette leçon</p>';
  }
}

function createVideoEmbed(url) {
  // YouTube
  if (url.includes('youtube.com') || url.includes('youtu.be')) {
    const videoId = url.includes('youtu.be')
      ? url.split('youtu.be/')[1]?.split('?')[0]
      : new URLSearchParams(url.split('?')[1]).get('v');

    return `
      <iframe
        width="100%"
        height="500"
        src="https://www.youtube.com/embed/${videoId}"
        frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen>
      </iframe>
    `;
  }

  // Vimeo
  if (url.includes('vimeo.com')) {
    const videoId = url.split('vimeo.com/')[1]?.split('?')[0];
    return `
      <iframe
        width="100%"
        height="500"
        src="https://player.vimeo.com/video/${videoId}"
        frameborder="0"
        allow="autoplay; fullscreen; picture-in-picture"
        allowfullscreen>
      </iframe>
    `;
  }

  // Generic video tag for direct URLs
  return `
    <video width="100%" height="500" controls>
      <source src="${url}" type="video/mp4">
      Votre navigateur ne supporte pas la lecture de vidéos.
    </video>
  `;
}

// ============================================
// NAVIGATION BUTTONS
// ============================================

function updateNavigationButtons() {
  const prevBtn = document.getElementById('prev-lesson-btn');
  const nextBtn = document.getElementById('next-lesson-btn');

  // Find current lesson index
  let allLessons = [];
  allModules.forEach(module => {
    module.lessons.filter(l => l.is_published).forEach(lesson => {
      allLessons.push({ ...lesson, module_id: module.id });
    });
  });

  const currentIndex = allLessons.findIndex(l => l.id === currentLessonId);

  // Previous button
  if (currentIndex > 0) {
    prevBtn.disabled = false;
    prevBtn.onclick = () => {
      const prevLesson = allLessons[currentIndex - 1];
      window.loadLesson(prevLesson.id, prevLesson.module_id);
    };
  } else {
    prevBtn.disabled = true;
  }

  // Next button
  if (currentIndex < allLessons.length - 1) {
    nextBtn.disabled = false;
    nextBtn.onclick = () => {
      const nextLesson = allLessons[currentIndex + 1];
      window.loadLesson(nextLesson.id, nextLesson.module_id);
    };
  } else {
    nextBtn.disabled = true;
  }
}

// ============================================
// MARK LESSON AS COMPLETE
// ============================================

async function markLessonComplete() {
  if (!currentLessonId) return;

  // Check if already completed
  if (completedLessonIds.includes(currentLessonId)) {
    showNotification('Cette leçon est déjà marquée comme terminée', 'info');
    return;
  }

  try {
    // Call API to mark lesson as complete
    const response = await api.markLessonComplete(currentLessonId);

    if (response.success) {
      showNotification('Leçon marquée comme terminée!', 'success');

      // Add to completed lessons
      completedLessonIds.push(currentLessonId);

      // Update lesson status in UI
      const lessonItem = document.querySelector(`[data-lesson-id="${currentLessonId}"] .lesson-status`);
      if (lessonItem) {
        lessonItem.textContent = '✅';
      }

      // Reload progress
      await loadCourseProgress();

      // Auto-load next lesson after a short delay
      const nextBtn = document.getElementById('next-lesson-btn');
      if (!nextBtn.disabled) {
        setTimeout(() => {
          nextBtn.click();
        }, 1500);
      }
    }
  } catch (error) {
    console.error('Erreur:', error);
    showNotification('Erreur lors de la sauvegarde de la progression', 'error');
  }
}

// ============================================
// PROGRESS DISPLAY
// ============================================

function updateProgressDisplay(percentage) {
  const progressArc = document.getElementById('progress-arc');
  const progressText = document.getElementById('progress-text');

  const circumference = 220; // 2 * π * r (r=35)
  const offset = circumference - (percentage / 100) * circumference;

  progressArc.style.strokeDashoffset = offset;
  progressText.textContent = `${Math.round(percentage)}%`;
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
  const markCompleteBtn = document.getElementById('mark-complete-btn');
  if (markCompleteBtn) {
    markCompleteBtn.addEventListener('click', markLessonComplete);
  }
}

// ============================================
// QUIZ FUNCTIONALITY
// ============================================

let currentQuizData = null;
let quizStartTime = null;
let selectedAnswers = {};

window.showModuleQuiz = async function(moduleId) {
  try {
    // Check if all lessons in module are completed
    const module = allModules.find(m => m.id === moduleId);
    if (!module) return;

    const publishedLessons = module.lessons.filter(l => l.is_published);
    const completedLessonsInModule = publishedLessons.filter(l => completedLessonIds.includes(l.id));

    if (completedLessonsInModule.length < publishedLessons.length) {
      showNotification('Terminez toutes les leçons avant de passer le quiz', 'warning');
      return;
    }

    // Load quiz
    const response = await api.getModuleQuiz(moduleId);
    if (!response.success || !response.data) {
      showNotification(response.message || 'Aucun quiz pour ce chapitre', 'info');
      return;
    }

    currentQuizData = response.data;
    currentModuleId = moduleId;
    selectedAnswers = {};
    quizStartTime = Date.now();

    // Hide other sections
    document.getElementById('welcome-section').classList.remove('active');
    document.getElementById('lesson-section').classList.remove('active');
    document.getElementById('quiz-section').classList.add('active');

    // Render quiz
    renderQuiz();
  } catch (error) {
    console.error('Erreur:', error);
    showNotification('Erreur lors du chargement du quiz', 'error');
  }
};

function renderQuiz() {
  const { quiz, questions, attempts } = currentQuizData;

  document.getElementById('quiz-title').textContent = quiz.title;
  document.getElementById('quiz-description').textContent = quiz.description || '';
  document.getElementById('quiz-questions-count').textContent = questions.length;
  document.getElementById('quiz-time-limit').textContent = quiz.time_limit_minutes;
  document.getElementById('quiz-passing-score').textContent = quiz.passing_score;

  const quizContent = document.getElementById('quiz-content');

  // Check if student has reached max attempts
  const attemptCount = attempts.length;
  const passedAttempts = attempts.filter(a => a.passed);

  if (passedAttempts.length > 0) {
    quizContent.innerHTML = `
      <div class="quiz-passed">
        <div class="success-icon">✅</div>
        <h3>Quiz déjà réussi!</h3>
        <p>Score: ${passedAttempts[0].percentage}%</p>
        <button class="btn btn-secondary" onclick="backToLessons()">Retour aux leçons</button>
      </div>
    `;
    return;
  }

  if (attemptCount >= quiz.max_attempts) {
    quizContent.innerHTML = `
      <div class="quiz-failed">
        <div class="error-icon">❌</div>
        <h3>Nombre maximum de tentatives atteint</h3>
        <p>Vous avez utilisé toutes vos tentatives (${quiz.max_attempts})</p>
        <p>Contactez votre instructeur pour réessayer.</p>
        <button class="btn btn-secondary" onclick="backToLessons()">Retour aux leçons</button>
      </div>
    `;
    return;
  }

  // Show previous attempts
  let attemptsHTML = '';
  if (attempts.length > 0) {
    attemptsHTML = `
      <div class="previous-attempts">
        <h4>Tentatives précédentes (${attempts.length}/${quiz.max_attempts}):</h4>
        ${attempts.map(a => `
          <div class="attempt-item ${a.passed ? 'passed' : 'failed'}">
            Score: ${a.percentage}% ${a.passed ? '✅' : '❌'} - ${new Date(a.completed_at).toLocaleDateString()}
          </div>
        `).join('')}
      </div>
    `;
  }

  // Render questions
  quizContent.innerHTML = `
    ${attemptsHTML}
    <div class="quiz-questions">
      ${questions.map((q, index) => `
        <div class="quiz-question" data-question-id="${q.id}">
          <h4>Question ${index + 1} (${q.points} point${q.points > 1 ? 's' : ''})</h4>
          <p class="question-text">${q.question_text}</p>
          <div class="quiz-answers">
            ${q.answers.map(answer => `
              <label class="quiz-answer-option">
                <input
                  type="radio"
                  name="question_${q.id}"
                  value="${answer.id}"
                  onchange="selectAnswer(${q.id}, ${answer.id})"
                >
                <span>${answer.answer_text}</span>
              </label>
            `).join('')}
          </div>
        </div>
      `).join('')}
    </div>
    <div class="quiz-actions">
      <button class="btn btn-secondary" onclick="backToLessons()">Annuler</button>
      <button class="btn btn-success" onclick="submitQuiz()">Soumettre le quiz</button>
    </div>
  `;
}

window.selectAnswer = function(questionId, answerId) {
  selectedAnswers[questionId] = answerId;
};

window.submitQuiz = async function() {
  try {
    const { quiz, questions } = currentQuizData;

    // Check all questions are answered
    if (Object.keys(selectedAnswers).length < questions.length) {
      showNotification('Veuillez répondre à toutes les questions', 'warning');
      return;
    }

    // Calculate time taken
    const timeTaken = Math.floor((Date.now() - quizStartTime) / 1000);

    // Format answers for API
    const answers = Object.keys(selectedAnswers).map(questionId => ({
      question_id: parseInt(questionId),
      answer_id: selectedAnswers[questionId]
    }));

    // Submit quiz
    const response = await api.submitQuizAttempt(currentModuleId, answers, timeTaken);

    if (response.success) {
      const { passed, percentage, passing_score } = response.data;

      if (passed) {
        showNotification(`Quiz réussi! Score: ${percentage}% 🎉`, 'success');
        // Reload progress and modules to update UI
        await loadCourseProgress();
        await loadModulesAndLessons();
        backToLessons();
      } else {
        showNotification(`Quiz échoué. Score: ${percentage}% (minimum: ${passing_score}%)`, 'error');
        // Reload quiz to show updated attempts
        await showModuleQuiz(currentModuleId);
      }
    }
  } catch (error) {
    console.error('Erreur:', error);
    showNotification('Erreur lors de la soumission du quiz', 'error');
  }
};

window.backToLessons = function() {
  document.getElementById('quiz-section').classList.remove('active');
  document.getElementById('welcome-section').classList.add('active');
};

// ============================================
// FINAL QUIZ MANAGEMENT
// ============================================

async function loadFinalQuiz() {
  try {
    const response = await api.getCourseFinalQuiz(currentCourseId);

    if (response.success && response.data && response.data.is_published) {
      finalQuiz = response.data;
      renderFinalQuizNav();
    } else {
      // No final quiz or not published
      finalQuiz = null;
      document.getElementById('final-quiz-nav').style.display = 'none';
    }
  } catch (error) {
    console.error('Erreur chargement quiz final:', error);
    finalQuiz = null;
    document.getElementById('final-quiz-nav').style.display = 'none';
  }
}

function renderFinalQuizNav() {
  const container = document.getElementById('final-quiz-nav');

  if (!finalQuiz) {
    container.style.display = 'none';
    return;
  }

  // Check if course is 100% complete
  const courseComplete = courseProgress && courseProgress.progress_percentage === 100;

  if (!courseComplete) {
    container.style.display = 'none';
    return;
  }

  // Show final quiz section
  container.style.display = 'block';

  container.innerHTML = `
    <div style="padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; margin: 15px; color: white;">
      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="font-size: 24px; margin-right: 10px;">🏆</span>
        <h3 style="margin: 0;">Quiz Final</h3>
      </div>
      <p style="margin: 10px 0; font-size: 14px; opacity: 0.95;">${finalQuiz.title}</p>
      <div style="display: flex; gap: 10px; margin: 15px 0; font-size: 12px;">
        <span>⏱️ ${finalQuiz.time_limit_minutes} min</span>
        <span>✓ ${finalQuiz.passing_score}%</span>
        <span>🔄 ${finalQuiz.max_attempts} tentatives</span>
      </div>
      <button onclick="startFinalQuiz()" class="btn btn-primary" style="width: 100%; background: white; color: #667eea; border: none; padding: 10px; border-radius: 6px; font-weight: 600; cursor: pointer;">
        Passer le quiz final
      </button>
    </div>
  `;
}

window.startFinalQuiz = async function() {
  if (!finalQuiz) {
    showNotification('Quiz final non disponible', 'error');
    return;
  }

  try {
    // Get quiz questions
    const questionsResponse = await api.getFinalQuizQuestions(finalQuiz.id);

    if (!questionsResponse.success || !questionsResponse.data || questionsResponse.data.length === 0) {
      showNotification('Aucune question disponible pour ce quiz', 'error');
      return;
    }

    const questions = questionsResponse.data;

    // Store quiz info for submission
    finalQuizAttempt = {
      id: finalQuiz.id,
      started_at: new Date()
    };

    // Hide lessons, show quiz
    document.getElementById('welcome-section').classList.remove('active');
    document.getElementById('lesson-section').classList.remove('active');

    // Render final quiz
    renderFinalQuizView(questions);

    document.getElementById('quiz-section').classList.add('active');

  } catch (error) {
    console.error('Erreur:', error);
    showNotification(error.message || 'Erreur lors du démarrage du quiz', 'error');
  }
};

function renderFinalQuizView(questions) {
  // Update quiz header
  document.getElementById('quiz-title').textContent = `🏆 ${finalQuiz.title}`;
  document.getElementById('quiz-description').textContent = finalQuiz.description || '';
  document.getElementById('quiz-questions-count').textContent = questions.length;
  document.getElementById('quiz-time-limit').textContent = finalQuiz.time_limit_minutes;
  document.getElementById('quiz-passing-score').textContent = finalQuiz.passing_score;

  // Build questions HTML
  let questionsHTML = '';
  questions.forEach((q, index) => {
    questionsHTML += `
      <div class="quiz-question">
        <h4>Question ${index + 1}</h4>
        <p class="question-text">${q.question_text}</p>
        <div class="quiz-answers">
          ${q.answers.map(answer => `
            <label class="quiz-answer-option">
              <input type="radio" name="question-${q.id}" value="${answer.id}">
              <span>${answer.answer_text}</span>
            </label>
          `).join('')}
        </div>
      </div>
    `;
  });

  // Render in quiz-content div
  const container = document.getElementById('quiz-content');
  container.innerHTML = `
    <div class="quiz-questions">
      ${questionsHTML}
    </div>

    <div class="quiz-actions">
      <button onclick="submitFinalQuiz()" class="btn btn-primary">Soumettre le quiz</button>
      <button onclick="cancelFinalQuiz()" class="btn btn-ghost">Annuler</button>
    </div>
  `;
}

window.submitFinalQuiz = async function() {
  if (!finalQuizAttempt) {
    showNotification('Aucune tentative de quiz en cours', 'error');
    return;
  }

  // Get all answers
  const answerInputs = document.querySelectorAll('#quiz-content input[type="radio"]:checked');

  if (answerInputs.length === 0) {
    showNotification('Veuillez répondre à au moins une question', 'error');
    return;
  }

  const answers = Array.from(answerInputs).map(input => ({
    question_id: parseInt(input.name.replace('question-', '')),
    answer_id: parseInt(input.value)
  }));

  try {
    const response = await api.submitFinalQuizAttempt(finalQuizAttempt.id, answers);

    if (response.success) {
      const { passed, score, total_questions } = response.data;
      const percentage = Math.round((score / total_questions) * 100);

      if (passed) {
        showNotification(`Félicitations! Vous avez réussi le quiz final! Score: ${percentage}% 🎉`, 'success');

        // Try to automatically generate the certificate
        try {
          console.log('Attempting to generate certificate for course:', currentCourseId);
          const certResponse = await api.generateCertificate(currentCourseId);
          console.log('Certificate generation response:', certResponse);

          if (certResponse.success) {
            showNotification('Félicitations! Votre certificat a été généré avec succès!', 'success');

            // Redirect to certificates page after 2 seconds
            console.log('Setting timeout for redirect (new certificate)...');
            setTimeout(() => {
              console.log('Redirecting to certificates page...');
              window.location.href = 'student-certificates.html';
            }, 2000);
          }
        } catch (certError) {
          // If certificate already exists or any other error, just show the message
          console.log('Certificate generation info:', certError);
          showNotification('Vous avez déjà un certificat pour ce cours! Redirection...', 'success');

          // Redirect to certificates page
          console.log('Setting timeout for redirect (existing certificate)...');
          setTimeout(() => {
            console.log('Redirecting to certificates page...');
            window.location.href = 'student-certificates.html';
          }, 2000);
        }

      } else {
        showNotification(`Quiz échoué. Score: ${percentage}%. Score requis: ${finalQuiz.passing_score}%`, 'error');
        backToLessons();
      }

    } else {
      showNotification(response.message || 'Erreur lors de la soumission', 'error');
    }
  } catch (error) {
    console.error('Erreur:', error);
    showNotification(error.message || 'Erreur lors de la soumission du quiz', 'error');
  }
};

window.cancelFinalQuiz = function() {
  if (confirm('Êtes-vous sûr de vouloir quitter le quiz? Votre progression ne sera pas sauvegardée.')) {
    finalQuizAttempt = null;
    backToLessons();
  }
};

// ============================================
// MOBILE MENU
// ============================================

function initMobileMenu() {
  const menuToggle = document.getElementById('mobile-menu-toggle');
  const sidebar = document.getElementById('course-sidebar');
  const overlay = document.getElementById('mobile-overlay');

  if (!menuToggle || !sidebar || !overlay) return;

  // Toggle menu
  menuToggle.addEventListener('click', () => {
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
  });

  // Close menu when overlay is clicked
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('active');
    overlay.classList.remove('active');
  });

  // Close menu when a lesson is selected (for better UX on mobile)
  document.addEventListener('click', (e) => {
    if (e.target.closest('.lesson-item, .quiz-item')) {
      sidebar.classList.remove('active');
      overlay.classList.remove('active');
    }
  });
}
