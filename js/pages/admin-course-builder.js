// ============================================
// ADMIN COURSE BUILDER - Module & Lesson Management
// ============================================

// CHECK AUTHENTICATION IMMEDIATELY (before page renders)
(function() {
  const user = getStorage('user');
  const token = localStorage.getItem('auth_token');

  // Redirect immediately if not authenticated or not authorized
  if (!token || !user || (user.role !== 'admin' && user.role !== 'instructor')) {
    window.location.href = '../index.html';
    // Stop script execution
    throw new Error('Access denied - redirecting');
  }
})();

let currentCourseId = null;
let currentModuleId = null;
let currentLessonId = null;
let currentQuizType = 'module'; // 'module' or 'final'
let allModules = [];

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', async () => {
  // Double-check authentication (defensive programming)
  const token = localStorage.getItem('auth_token');
  if (!token) {
    window.location.href = 'admin-login.html';
    return;
  }

  const user = getStorage('user');
  if (!user || (user.role !== 'admin' && user.role !== 'instructor')) {
    window.location.href = '../index.html';
    return;
  }

  // Get courseId from URL
  const urlParams = new URLSearchParams(window.location.search);
  currentCourseId = urlParams.get('courseId');

  if (!currentCourseId) {
    alert('ID de cours manquant');
    window.location.href = 'admin-dashboard.html';
    return;
  }

  // Load course details
  await loadCourseDetails();

  // Load modules
  await loadModules();

  // Load final quiz
  await loadFinalQuiz();

  // Setup event listeners
  setupEventListeners();
});

// ============================================
// LOAD COURSE DETAILS
// ============================================

async function loadCourseDetails() {
  try {
    const response = await api.getCourse(currentCourseId);
    const course = response.data;

    document.getElementById('course-title').textContent = course.title;
    document.getElementById('course-subtitle').textContent = course.description || '';
  } catch (error) {
    console.error('Erreur lors du chargement du cours:', error);
    alert('Erreur lors du chargement du cours');
    window.location.href = 'admin-dashboard.html';
  }
}

// ============================================
// LOAD MODULES
// ============================================

async function loadModules() {
  try {
    const response = await api.getCourseModules(currentCourseId);
    allModules = response.data || [];

    renderModules();
  } catch (error) {
    console.error('Erreur lors du chargement des chapitres:', error);
    showError('Erreur lors du chargement des chapitres');
  }
}

function renderModules() {
  const container = document.getElementById('modules-container');

  if (allModules.length === 0) {
    container.innerHTML = '<p class="empty-state">Aucun chapitre. Commencez par en ajouter un!</p>';
    return;
  }

  container.innerHTML = allModules.map(module => createModuleCard(module)).join('');
}

function createModuleCard(module) {
  const publishedBadge = module.is_published
    ? '<span class="badge badge-success">Publié</span>'
    : '<span class="badge badge-warning">Brouillon</span>';

  return `
    <div class="module-card" data-module-id="${module.id}">
      <div class="module-header">
        <div class="module-title-section">
          <h3 class="module-title">Chapitre ${module.order_position}: ${module.title}</h3>
          ${publishedBadge}
        </div>
        <div class="module-actions">
          <button class="btn btn-ghost btn-sm" onclick="editModule(${module.id})"><i class="ti ti-pencil"></i> Modifier</button>
          <button class="btn btn-primary btn-sm" onclick="addLesson(${module.id})"><i class="ti ti-plus"></i> Ajouter une leçon</button>
          <button class="btn btn-danger btn-sm" onclick="deleteModule(${module.id})"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      <div class="module-description">
        ${module.description || '<em>Pas de description</em>'}
      </div>
      <div class="lessons-container" id="lessons-${module.id}">
        <p class="text-muted">Chargement des leçons...</p>
      </div>
      <div class="quiz-container" id="quiz-${module.id}">
        <p class="text-muted">Chargement du quiz...</p>
      </div>
    </div>
  `;
}

// ============================================
// LOAD LESSONS FOR MODULE
// ============================================

async function loadModuleLessons(moduleId) {
  try {
    const response = await api.getModuleLessons(moduleId);
    const lessons = response.data || [];

    renderLessons(moduleId, lessons);
  } catch (error) {
    console.error('Erreur lors du chargement des leçons:', error);
    document.getElementById(`lessons-${moduleId}`).innerHTML =
      '<p class="text-danger">Erreur lors du chargement des leçons</p>';
  }
}

function renderLessons(moduleId, lessons) {
  const container = document.getElementById(`lessons-${moduleId}`);

  if (lessons.length === 0) {
    container.innerHTML = '<p class="empty-state-small">Aucune leçon. Cliquez sur "Ajouter une leçon".</p>';
    return;
  }

  container.innerHTML = `
    <div class="lessons-list">
      ${lessons.map(lesson => createLessonCard(lesson)).join('')}
    </div>
  `;
}

function createLessonCard(lesson) {
  const previewBadge = lesson.is_preview
    ? '<span class="badge badge-info">Aperçu gratuit</span>'
    : '';
  const publishedBadge = lesson.is_published
    ? '<span class="badge badge-success">Publié</span>'
    : '<span class="badge badge-warning">Brouillon</span>';

  const videoBadge = lesson.video_url
    ? `<span class="badge badge-primary"><i class="ti ti-video"></i> ${lesson.duration_minutes || 0} min</span>`
    : '';

  return `
    <div class="lesson-card" data-lesson-id="${lesson.id}">
      <div class="lesson-info">
        <div class="lesson-number">Leçon ${lesson.order_position}</div>
        <div class="lesson-details">
          <h4 class="lesson-title">${lesson.title}</h4>
          <p class="lesson-description">${lesson.description || ''}</p>
          <div class="lesson-badges">
            ${publishedBadge}
            ${previewBadge}
            ${videoBadge}
          </div>
        </div>
      </div>
      <div class="lesson-actions">
        <button class="btn btn-ghost btn-sm" onclick="editLesson(${lesson.id})"><i class="ti ti-pencil"></i></button>
        <button class="btn btn-danger btn-sm" onclick="deleteLesson(${lesson.id})"><i class="ti ti-trash"></i></button>
      </div>
    </div>
  `;
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
  // Add module button
  document.getElementById('add-module-btn').addEventListener('click', () => {
    currentModuleId = null;
    openModuleModal();
  });

  // Module form submission
  document.getElementById('module-form').addEventListener('submit', handleModuleSubmit);

  // Lesson form submission
  document.getElementById('lesson-form').addEventListener('submit', handleLessonSubmit);

  // Quiz form submission
  document.getElementById('quiz-form').addEventListener('submit', handleQuizSubmit);

  // Question form submission
  document.getElementById('question-form').addEventListener('submit', handleQuestionSubmit);

  // Answer form submission
  document.getElementById('answer-form').addEventListener('submit', handleAnswerSubmit);

  // Final quiz button
  const createFinalQuizBtn = document.getElementById('create-final-quiz-btn');
  if (createFinalQuizBtn) {
    createFinalQuizBtn.addEventListener('click', () => {
      openFinalQuizModal();
    });
  }

  // Final quiz form submission
  document.getElementById('final-quiz-form').addEventListener('submit', handleFinalQuizSubmit);

  // Logout button
  const logoutBtn = document.getElementById('logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      await api.logout();
      window.location.href = 'admin-login.html';
    });
  }

  // Load lessons and quiz when modules are rendered
  setTimeout(() => {
    allModules.forEach(module => {
      loadModuleLessons(module.id);
      loadModuleQuiz(module.id);
    });
  }, 100);
}

// ============================================
// MODULE MODAL
// ============================================

function openModuleModal(module = null) {
  const modal = document.getElementById('module-modal');
  const title = document.querySelector('#module-modal .modal-title');

  if (module) {
    title.textContent = 'Modifier un chapitre';
    document.getElementById('module-title').value = module.title;
    document.getElementById('module-description').value = module.description || '';
    document.getElementById('module-published').checked = module.is_published;
  } else {
    title.textContent = 'Ajouter un chapitre';
    document.getElementById('module-form').reset();
  }

  modal.style.display = 'flex';
}

async function handleModuleSubmit(e) {
  e.preventDefault();

  const data = {
    title: document.getElementById('module-title').value,
    description: document.getElementById('module-description').value,
    is_published: document.getElementById('module-published').checked
  };

  try {
    if (currentModuleId) {
      await api.updateModule(currentModuleId, data);
      showSuccess('Chapitre mis à jour avec succès');
    } else {
      await api.createModule(currentCourseId, data);
      showSuccess('Chapitre créé avec succès');
    }

    closeModal('module-modal');
    await loadModules();
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement du chapitre:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement du chapitre');
  }
}

window.editModule = async function(moduleId) {
  try {
    const response = await api.getModule(moduleId);
    const module = response.data;
    currentModuleId = moduleId;
    openModuleModal(module);
  } catch (error) {
    console.error('Erreur lors du chargement du chapitre:', error);
    showError('Erreur lors du chargement du chapitre');
  }
};

window.deleteModule = async function(moduleId) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer ce chapitre et toutes ses leçons ?')) {
    return;
  }

  try {
    await api.deleteModule(moduleId);
    showSuccess('Chapitre supprimé avec succès');
    await loadModules();
  } catch (error) {
    console.error('Erreur lors de la suppression du chapitre:', error);
    showError(error.message || 'Erreur lors de la suppression du chapitre');
  }
};

// ============================================
// LESSON MODAL
// ============================================

window.addLesson = function(moduleId) {
  currentModuleId = moduleId;
  currentLessonId = null;
  openLessonModal();
};

function openLessonModal(lesson = null) {
  const modal = document.getElementById('lesson-modal');
  const title = document.querySelector('#lesson-modal .modal-title');

  if (lesson) {
    title.textContent = 'Modifier une leçon';
    document.getElementById('lesson-title').value = lesson.title;
    document.getElementById('lesson-description').value = lesson.description || '';
    document.getElementById('lesson-content').value = lesson.content || '';
    document.getElementById('lesson-video').value = lesson.video_url || '';
    document.getElementById('lesson-duration').value = lesson.duration_minutes || '';
    document.getElementById('lesson-preview').checked = lesson.is_preview;
    document.getElementById('lesson-published').checked = lesson.is_published;
  } else {
    title.textContent = 'Ajouter une leçon';
    document.getElementById('lesson-form').reset();
  }

  modal.style.display = 'flex';
}

async function handleLessonSubmit(e) {
  e.preventDefault();

  const data = {
    title: document.getElementById('lesson-title').value,
    description: document.getElementById('lesson-description').value,
    content: document.getElementById('lesson-content').value,
    video_url: document.getElementById('lesson-video').value || null,
    duration_minutes: parseInt(document.getElementById('lesson-duration').value) || null,
    is_preview: document.getElementById('lesson-preview').checked,
    is_published: document.getElementById('lesson-published').checked
  };

  try {
    if (currentLessonId) {
      await api.updateLesson(currentLessonId, data);
      showSuccess('Leçon mise à jour avec succès');
    } else {
      await api.createLesson(currentModuleId, data);
      showSuccess('Leçon créée avec succès');
    }

    closeModal('lesson-modal');
    await loadModuleLessons(currentModuleId);
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement de la leçon:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement de la leçon');
  }
}

window.editLesson = async function(lessonId) {
  try {
    const response = await api.getLesson(lessonId);
    const lesson = response.data;
    currentLessonId = lessonId;
    currentModuleId = lesson.module_id;
    openLessonModal(lesson);
  } catch (error) {
    console.error('Erreur lors du chargement de la leçon:', error);
    showError('Erreur lors du chargement de la leçon');
  }
};

window.deleteLesson = async function(lessonId) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette leçon ?')) {
    return;
  }

  try {
    // Get lesson to know which module to refresh
    const response = await api.getLesson(lessonId);
    const moduleId = response.data.module_id;

    await api.deleteLesson(lessonId);
    showSuccess('Leçon supprimée avec succès');
    await loadModuleLessons(moduleId);
  } catch (error) {
    console.error('Erreur lors de la suppression de la leçon:', error);
    showError(error.message || 'Erreur lors de la suppression de la leçon');
  }
};

// ============================================
// FORM HANDLERS
// ============================================

async function handleQuizSubmit(e) {
  e.preventDefault();

  const data = {
    title: document.getElementById('quiz-title').value,
    description: document.getElementById('quiz-description').value,
    passing_score: parseInt(document.getElementById('quiz-passing-score').value),
    time_limit_minutes: parseInt(document.getElementById('quiz-time-limit').value),
    max_attempts: parseInt(document.getElementById('quiz-max-attempts').value),
    is_required: document.getElementById('quiz-required').checked,
    is_published: document.getElementById('quiz-published').checked
  };

  try {
    if (currentQuizId) {
      await api.updateQuiz(currentQuizId, data);
      showSuccess('Quiz mis à jour avec succès');
    } else {
      await api.createModuleQuiz(currentModuleId, data);
      showSuccess('Quiz créé avec succès');
    }

    closeModal('quiz-modal');
    await loadModuleQuiz(currentModuleId);
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement du quiz:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement du quiz');
  }
}

async function handleQuestionSubmit(e) {
  e.preventDefault();

  const data = {
    question_text: document.getElementById('question-text').value,
    question_type: document.getElementById('question-type').value,
    points: parseInt(document.getElementById('question-points').value),
    explanation: document.getElementById('question-explanation').value
  };

  try {
    if (currentQuestionId) {
      await api.updateQuizQuestion(currentQuestionId, data);
      showSuccess('Question mise à jour avec succès');
    } else {
      // Use different endpoint based on quiz type
      if (currentQuizType === 'final') {
        await api.createFinalQuizQuestion(currentQuizId, data);
      } else {
        await api.createQuizQuestion(currentQuizId, data);
      }
      showSuccess('Question créée avec succès');
    }

    closeModal('question-modal');
    await loadQuestions(currentQuizId);
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement de la question:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement de la question');
  }
}

async function handleAnswerSubmit(e) {
  e.preventDefault();

  const data = {
    answer_text: document.getElementById('answer-text').value,
    is_correct: document.getElementById('answer-correct').checked
  };

  try {
    if (currentAnswerId) {
      await api.updateQuestionAnswer(currentAnswerId, data);
      showSuccess('Réponse mise à jour avec succès');
    } else {
      await api.createQuestionAnswer(currentQuestionId, data);
      showSuccess('Réponse créée avec succès');
    }

    closeModal('answer-modal');
    await loadAnswers(currentQuestionId);
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement de la réponse:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement de la réponse');
  }
}

// ============================================
// QUIZ MANAGEMENT
// ============================================

let currentQuizId = null;
let currentQuestionId = null;
let moduleQuizzes = {}; // Store quizzes by module ID

async function loadModuleQuiz(moduleId) {
  try {
    const response = await api.getModuleQuizAdmin(moduleId);
    const quiz = response.data;

    moduleQuizzes[moduleId] = quiz;
    renderQuiz(moduleId, quiz);
  } catch (error) {
    console.error('Erreur lors du chargement du quiz:', error);
    document.getElementById(`quiz-${moduleId}`).innerHTML =
      '<p class="text-danger">Erreur lors du chargement du quiz</p>';
  }
}

function renderQuiz(moduleId, quiz) {
  const container = document.getElementById(`quiz-${moduleId}`);

  if (!quiz) {
    container.innerHTML = `
      <div class="quiz-section">
        <div class="quiz-header">
          <h4><i class="ti ti-file-text"></i> Quiz du chapitre</h4>
          <button class="btn btn-primary btn-sm" onclick="createQuiz(${moduleId})"><i class="ti ti-plus"></i> Créer un quiz</button>
        </div>
      </div>
    `;
    return;
  }

  const publishedBadge = quiz.is_published
    ? '<span class="badge badge-success">Publié</span>'
    : '<span class="badge badge-warning">Brouillon</span>';

  container.innerHTML = `
    <div class="quiz-section">
      <div class="quiz-header">
        <div class="quiz-title-section">
          <h4><i class="ti ti-file-text"></i> ${quiz.title}</h4>
          ${publishedBadge}
          <span class="badge badge-info">${quiz.passing_score}% requis</span>
          <span class="badge badge-secondary">${quiz.time_limit_minutes} min</span>
        </div>
        <div class="quiz-actions">
          <button class="btn btn-ghost btn-sm" onclick="editQuiz(${quiz.id}, ${moduleId})"><i class="ti ti-pencil"></i> Modifier</button>
          <button class="btn btn-primary btn-sm" onclick="manageQuestions(${quiz.id}, ${moduleId})"><i class="ti ti-clipboard-text"></i> Gérer les questions</button>
          <button class="btn btn-danger btn-sm" onclick="deleteQuizConfirm(${quiz.id}, ${moduleId})"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      <div class="quiz-description">
        ${quiz.description || '<em>Pas de description</em>'}
      </div>
      <div class="questions-list" id="questions-${quiz.id}">
        <!-- Questions will be loaded here -->
      </div>
    </div>
  `;
}

window.createQuiz = function(moduleId) {
  currentModuleId = moduleId;
  currentQuizId = null;
  openQuizModal();
};

window.editQuiz = async function(quizId, moduleId) {
  try {
    const quiz = moduleQuizzes[moduleId];
    currentQuizId = quizId;
    currentModuleId = moduleId;
    openQuizModal(quiz);
  } catch (error) {
    console.error('Erreur lors du chargement du quiz:', error);
    showError('Erreur lors du chargement du quiz');
  }
};

window.deleteQuizConfirm = async function(quizId, moduleId) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer ce quiz et toutes ses questions ?')) {
    return;
  }

  try {
    await api.deleteQuiz(quizId);
    showSuccess('Quiz supprimé avec succès');
    await loadModuleQuiz(moduleId);
  } catch (error) {
    console.error('Erreur lors de la suppression du quiz:', error);
    showError(error.message || 'Erreur lors de la suppression du quiz');
  }
};

function openQuizModal(quiz = null) {
  const modal = document.getElementById('quiz-modal');
  const title = document.querySelector('#quiz-modal .modal-title');

  if (quiz) {
    title.textContent = 'Modifier le quiz';
    document.getElementById('quiz-title').value = quiz.title;
    document.getElementById('quiz-description').value = quiz.description || '';
    document.getElementById('quiz-passing-score').value = quiz.passing_score;
    document.getElementById('quiz-time-limit').value = quiz.time_limit_minutes;
    document.getElementById('quiz-max-attempts').value = quiz.max_attempts;
    document.getElementById('quiz-required').checked = quiz.is_required;
    document.getElementById('quiz-published').checked = quiz.is_published;
  } else {
    title.textContent = 'Créer un quiz';
    document.getElementById('quiz-form').reset();
    // Set default values
    document.getElementById('quiz-passing-score').value = 70;
    document.getElementById('quiz-time-limit').value = 30;
    document.getElementById('quiz-max-attempts').value = 3;
    document.getElementById('quiz-required').checked = true;
  }

  modal.style.display = 'flex';
}

window.manageQuestions = async function(quizId, moduleId) {
  currentQuizId = quizId;
  currentModuleId = moduleId;
  currentQuizType = 'module'; // Reset to module type
  await loadQuestions(quizId);
  openQuestionsModal();
};

async function loadQuestions(quizId) {
  try {
    let response;
    // Use different endpoint based on quiz type
    if (currentQuizType === 'final') {
      response = await api.getFinalQuizQuestions(quizId);
    } else {
      response = await api.getQuizQuestions(quizId);
    }
    const questions = response.data || [];
    renderQuestions(questions);
  } catch (error) {
    console.error('Erreur lors du chargement des questions:', error);
    showError('Erreur lors du chargement des questions');
  }
}

function renderQuestions(questions) {
  const container = document.getElementById('questions-list-modal');

  if (questions.length === 0) {
    container.innerHTML = '<p class="empty-state-small">Aucune question. Cliquez sur "Ajouter une question".</p>';
    return;
  }

  container.innerHTML = questions.map(question => `
    <div class="question-card">
      <div class="question-header">
        <div class="question-info">
          <strong>Q${question.order_position + 1}:</strong> ${question.question_text}
          <span class="badge badge-primary">${question.points} pt${question.points > 1 ? 's' : ''}</span>
          <span class="badge badge-secondary">${question.answer_count} réponse${question.answer_count > 1 ? 's' : ''}</span>
        </div>
        <div class="question-actions">
          <button class="btn btn-ghost btn-sm" onclick="editQuestion(${question.id})"><i class="ti ti-pencil"></i></button>
          <button class="btn btn-primary btn-sm" onclick="manageAnswers(${question.id})">Réponses</button>
          <button class="btn btn-danger btn-sm" onclick="deleteQuestion(${question.id})"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      ${question.explanation ? `<p class="question-explanation"><em>Explication: ${question.explanation}</em></p>` : ''}
    </div>
  `).join('');
}

function openQuestionsModal() {
  const modal = document.getElementById('questions-modal');
  modal.style.display = 'flex';
}

window.addQuestion = function() {
  currentQuestionId = null;
  openQuestionModal();
};

window.editQuestion = async function(questionId) {
  currentQuestionId = questionId;
  // Load question details
  try {
    const response = await api.getQuizQuestions(currentQuizId);
    const question = response.data.find(q => q.id === questionId);
    openQuestionModal(question);
  } catch (error) {
    console.error('Erreur:', error);
    showError('Erreur lors du chargement de la question');
  }
};

window.deleteQuestion = async function(questionId) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette question ?')) {
    return;
  }

  try {
    await api.deleteQuizQuestion(questionId);
    showSuccess('Question supprimée avec succès');
    await loadQuestions(currentQuizId);
  } catch (error) {
    console.error('Erreur:', error);
    showError('Erreur lors de la suppression de la question');
  }
};

function openQuestionModal(question = null) {
  const modal = document.getElementById('question-modal');
  const title = document.querySelector('#question-modal .modal-title');

  if (question) {
    title.textContent = 'Modifier la question';
    document.getElementById('question-text').value = question.question_text;
    document.getElementById('question-type').value = question.question_type;
    document.getElementById('question-points').value = question.points;
    document.getElementById('question-explanation').value = question.explanation || '';
  } else {
    title.textContent = 'Ajouter une question';
    document.getElementById('question-form').reset();
    document.getElementById('question-points').value = 1;
  }

  modal.style.display = 'flex';
}

window.manageAnswers = async function(questionId) {
  currentQuestionId = questionId;
  await loadAnswers(questionId);
  openAnswersModal();
};

async function loadAnswers(questionId) {
  try {
    const response = await api.getQuestionAnswers(questionId);
    const answers = response.data || [];
    renderAnswers(answers);
  } catch (error) {
    console.error('Erreur:', error);
    showError('Erreur lors du chargement des réponses');
  }
}

function renderAnswers(answers) {
  const container = document.getElementById('answers-list-modal');

  if (answers.length === 0) {
    container.innerHTML = '<p class="empty-state-small">Aucune réponse. Cliquez sur "Ajouter une réponse".</p>';
    return;
  }

  container.innerHTML = answers.map(answer => `
    <div class="answer-card ${answer.is_correct ? 'correct-answer' : ''}">
      <div class="answer-info">
        ${answer.is_correct ? '<i class="ti ti-check"></i>' : '<i class="ti ti-circle"></i>'} ${answer.answer_text}
      </div>
      <div class="answer-actions">
        <button class="btn btn-ghost btn-sm" onclick="editAnswer(${answer.id})"><i class="ti ti-pencil"></i></button>
        <button class="btn btn-danger btn-sm" onclick="deleteAnswer(${answer.id})"><i class="ti ti-trash"></i></button>
      </div>
    </div>
  `).join('');
}

function openAnswersModal() {
  const modal = document.getElementById('answers-modal');
  modal.style.display = 'flex';
}

window.addAnswer = function() {
  openAnswerModal();
};

window.editAnswer = async function(answerId) {
  try {
    const response = await api.getQuestionAnswers(currentQuestionId);
    const answer = response.data.find(a => a.id === answerId);
    openAnswerModal(answer);
  } catch (error) {
    console.error('Erreur:', error);
    showError('Erreur lors du chargement de la réponse');
  }
};

window.deleteAnswer = async function(answerId) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette réponse ?')) {
    return;
  }

  try {
    await api.deleteQuestionAnswer(answerId);
    showSuccess('Réponse supprimée avec succès');
    await loadAnswers(currentQuestionId);
  } catch (error) {
    console.error('Erreur:', error);
    showError('Erreur lors de la suppression de la réponse');
  }
};

let currentAnswerId = null;

function openAnswerModal(answer = null) {
  const modal = document.getElementById('answer-modal');
  const title = document.querySelector('#answer-modal .modal-title');

  if (answer) {
    title.textContent = 'Modifier la réponse';
    document.getElementById('answer-text').value = answer.answer_text;
    document.getElementById('answer-correct').checked = answer.is_correct;
    currentAnswerId = answer.id;
  } else {
    title.textContent = 'Ajouter une réponse';
    document.getElementById('answer-form').reset();
    currentAnswerId = null;
  }

  modal.style.display = 'flex';
}

// ============================================
// MODAL UTILITIES
// ============================================

window.closeModal = function(modalId) {
  const modal = document.getElementById(modalId);
  modal.style.display = 'none';
};

// Close modal when clicking outside
window.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal')) {
    e.target.style.display = 'none';
  }
});

// ============================================
// NOTIFICATION UTILITIES
// ============================================

function showSuccess(message) {
  // Reuse existing notification system if available, or simple alert
  if (window.showNotification) {
    window.showNotification(message, 'success');
  } else {
    alert(message);
  }
}

function showError(message) {
  if (window.showNotification) {
    window.showNotification(message, 'error');
  } else {
    alert('Erreur: ' + message);
  }
}

// ============================================
// FINAL QUIZ MANAGEMENT
// ============================================

let currentFinalQuiz = null;

async function loadFinalQuiz() {
  try {
    const response = await api.getCourseFinalQuizAdmin(currentCourseId);

    if (response.success && response.data) {
      currentFinalQuiz = response.data;
      renderFinalQuiz();
    } else {
      // No final quiz exists
      currentFinalQuiz = null;
      renderNoFinalQuiz();
    }
  } catch (error) {
    console.error('Erreur chargement quiz final:', error);
    currentFinalQuiz = null;
    renderNoFinalQuiz();
  }
}

function renderNoFinalQuiz() {
  const container = document.getElementById('final-quiz-container');
  const createBtn = document.getElementById('create-final-quiz-btn');

  container.innerHTML = `
    <div class="empty-state" style="text-align: center; padding: 40px; background: white; border-radius: 8px;">
      <p style="color: #666; margin-bottom: 20px;">Aucun quiz final pour ce cours</p>
      <p style="color: #999; font-size: 14px;">Créez un quiz final pour tester les connaissances globales</p>
    </div>
  `;

  createBtn.style.display = 'block';
}

function renderFinalQuiz() {
  const container = document.getElementById('final-quiz-container');
  const createBtn = document.getElementById('create-final-quiz-btn');

  createBtn.style.display = 'none';

  const statusBadge = currentFinalQuiz.is_published
    ? '<span class="badge badge-success">Publié</span>'
    : '<span class="badge badge-warning">Brouillon</span>';

  container.innerHTML = `
    <div class="final-quiz-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
      <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
        <div style="flex: 1;">
          <h3 style="margin: 0 0 5px 0;">${currentFinalQuiz.title}</h3>
          <p style="margin: 0; color: #666; font-size: 14px;">${currentFinalQuiz.description || 'Pas de description'}</p>
        </div>
        <div style="margin-left: 20px;">
          ${statusBadge}
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px;">
        <div>
          <div style="font-size: 12px; color: #666;">Score de passage</div>
          <div style="font-weight: 600;">${currentFinalQuiz.passing_score}%</div>
        </div>
        <div>
          <div style="font-size: 12px; color: #666;">Temps limite</div>
          <div style="font-weight: 600;">${currentFinalQuiz.time_limit_minutes} min</div>
        </div>
        <div>
          <div style="font-size: 12px; color: #666;">Tentatives</div>
          <div style="font-weight: 600;">${currentFinalQuiz.max_attempts}</div>
        </div>
        <div>
          <div style="font-size: 12px; color: #666;">Questions</div>
          <div style="font-weight: 600;">${currentFinalQuiz.question_count || 0}</div>
        </div>
      </div>

      <div style="display: flex; gap: 10px; margin-top: 15px;">
        <button onclick="editFinalQuiz()" class="btn btn-primary btn-sm"><i class="ti ti-pencil"></i> Modifier</button>
        <button onclick="manageFinalQuizQuestions()" class="btn btn-ghost btn-sm"><i class="ti ti-file-text"></i> Gérer les questions</button>
        <button onclick="deleteFinalQuiz()" class="btn btn-danger btn-sm" style="margin-left: auto;"><i class="ti ti-trash"></i> Supprimer</button>
      </div>
    </div>
  `;
}

function openFinalQuizModal(quiz = null) {
  const modal = document.getElementById('final-quiz-modal');
  const form = document.getElementById('final-quiz-form');
  const titleInput = document.getElementById('final-quiz-title');
  const descriptionInput = document.getElementById('final-quiz-description');
  const passingScoreInput = document.getElementById('final-quiz-passing-score');
  const timeLimitInput = document.getElementById('final-quiz-time-limit');
  const maxAttemptsInput = document.getElementById('final-quiz-max-attempts');
  const publishedInput = document.getElementById('final-quiz-published');

  form.reset();

  if (quiz) {
    titleInput.value = quiz.title;
    descriptionInput.value = quiz.description || '';
    passingScoreInput.value = quiz.passing_score;
    timeLimitInput.value = quiz.time_limit_minutes;
    maxAttemptsInput.value = quiz.max_attempts;
    publishedInput.checked = quiz.is_published;
  } else {
    passingScoreInput.value = 80;
    timeLimitInput.value = 60;
    maxAttemptsInput.value = 2;
  }

  modal.style.display = 'flex';
}

async function handleFinalQuizSubmit(e) {
  e.preventDefault();

  const data = {
    title: document.getElementById('final-quiz-title').value,
    description: document.getElementById('final-quiz-description').value,
    passing_score: parseInt(document.getElementById('final-quiz-passing-score').value),
    time_limit_minutes: parseInt(document.getElementById('final-quiz-time-limit').value),
    max_attempts: parseInt(document.getElementById('final-quiz-max-attempts').value),
    is_published: document.getElementById('final-quiz-published').checked
  };

  try {
    let response;
    if (currentFinalQuiz) {
      // Update existing quiz
      response = await api.updateFinalQuiz(currentFinalQuiz.id, data);
      showSuccess('Quiz final mis à jour avec succès');
    } else {
      // Create new quiz
      response = await api.createCourseFinalQuiz(currentCourseId, data);
      showSuccess('Quiz final créé avec succès');
    }

    closeModal('final-quiz-modal');
    await loadFinalQuiz();
  } catch (error) {
    console.error('Erreur:', error);
    showError(error.message || 'Erreur lors de l\'enregistrement du quiz final');
  }
}

function editFinalQuiz() {
  openFinalQuizModal(currentFinalQuiz);
}

async function deleteFinalQuiz() {
  if (!confirm('Êtes-vous sûr de vouloir supprimer ce quiz final ? Cette action est irréversible.')) {
    return;
  }

  try {
    await api.deleteFinalQuiz(currentFinalQuiz.id);
    showSuccess('Quiz final supprimé avec succès');
    await loadFinalQuiz();
  } catch (error) {
    console.error('Erreur:', error);
    showError(error.message || 'Erreur lors de la suppression du quiz final');
  }
}

async function manageFinalQuizQuestions() {
  // Open questions modal in the same way as module quizzes
  // We can reuse the existing quiz/question/answer modals
  currentQuizId = currentFinalQuiz.id;
  currentQuizType = 'final';
  currentModuleId = null; // No module for final quiz
  await loadQuestions(currentFinalQuiz.id);
  openQuestionsModal();
}
