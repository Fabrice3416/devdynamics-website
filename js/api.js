// ============================================
// API CLIENT
// ============================================

// Détection automatique de l'environnement
const API_BASE_URL = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
  ? 'http://localhost/api'
  : window.location.origin + '/api';

class APIClient {
  constructor() {
    this.token = localStorage.getItem('auth_token');
  }

  setToken(token) {
    this.token = token;
    localStorage.setItem('auth_token', token);
  }

  getHeaders(method = 'GET') {
    const headers = {
      'Content-Type': 'application/json'
    };
    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }
    // Pour les serveurs qui ne supportent pas PUT/DELETE nativement
    if (method === 'PUT' || method === 'DELETE') {
      headers['X-HTTP-Method-Override'] = method;
    }
    return headers;
  }

  async request(endpoint, options = {}) {
    const url = `${API_BASE_URL}${endpoint}`;
    const method = options.method || 'GET';

    const config = {
      ...options,
      headers: this.getHeaders(method)
    };

    // Convertir PUT/DELETE en POST si nécessaire pour compatibilité serveur
    if (method === 'PUT' || method === 'DELETE') {
      config.method = 'POST';
    }

    try {
      const response = await fetch(url, config);
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Erreur API');
      }

      return data;
    } catch (error) {
      console.error('Erreur API:', error);
      throw error;
    }
  }

  // Auth (Admin)
  async login(email, password) {
    return this.request('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
  }

  async logout() {
    this.token = null;
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user_data');
  }

  // Student Auth
  async studentRegister(data) {
    return this.request('/students/register', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async studentLogin(email, password) {
    return this.request('/students/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
  }

  async getStudentProfile() {
    return this.request('/students/profile');
  }

  async updateStudentProfile(data) {
    return this.request('/students/profile', {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async changePassword(current_password, new_password) {
    return this.request('/students/change-password', {
      method: 'PUT',
      body: JSON.stringify({ current_password, new_password })
    });
  }

  async getStudentEnrollments() {
    return this.request('/students/enrollments');
  }

  async getCourseProgress(courseId) {
    return this.request(`/students/courses/${courseId}/progress`);
  }

  // Organization
  async getOrganizationInfo() {
    return this.request('/organization/info');
  }

  async getFounders() {
    return this.request('/organization/founders');
  }

  async updateOrganizationInfo(data) {
    return this.request('/organization/info', {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  // Programs
  async getPrograms() {
    return this.request('/programs');
  }

  async getProgram(id) {
    return this.request(`/programs/${id}`);
  }

  async createProgram(data) {
    return this.request('/programs', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateProgram(id, data) {
    return this.request(`/programs/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteProgram(id) {
    return this.request(`/programs/${id}`, {
      method: 'DELETE'
    });
  }

  // Testimonials
  async getTestimonials() {
    return this.request('/testimonials');
  }

  async getFeaturedTestimonials() {
    return this.request('/testimonials/featured');
  }

  async createTestimonial(data) {
    return this.request('/testimonials', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateTestimonial(id, data) {
    return this.request(`/testimonials/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteTestimonial(id) {
    return this.request(`/testimonials/${id}`, {
      method: 'DELETE'
    });
  }

  // Sponsors
  async getSponsors() {
    return this.request('/sponsors');
  }

  async getAllSponsors() {
    return this.request('/sponsors/all');
  }

  async createSponsor(data) {
    return this.request('/sponsors', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateSponsor(id, data) {
    return this.request(`/sponsors/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteSponsor(id) {
    return this.request(`/sponsors/${id}`, {
      method: 'DELETE'
    });
  }

  // Blog
  async getBlogPosts(page = 1, limit = 10) {
    return this.request(`/blog?page=${page}&limit=${limit}`);
  }

  async getBlogPost(slug) {
    return this.request(`/blog/${slug}`);
  }

  async createBlogPost(data) {
    return this.request('/blog', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateBlogPost(id, data) {
    return this.request(`/blog/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteBlogPost(id) {
    return this.request(`/blog/${id}`, {
      method: 'DELETE'
    });
  }

  // Donations
  async getDonations() {
    return this.request('/donations');
  }

  async getDonationStats() {
    return this.request('/donations/stats');
  }

  async createDonation(data) {
    return this.request('/donations', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateDonationStatus(id, status) {
    return this.request(`/donations/${id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status })
    });
  }

  // Contact
  async getContactMessages() {
    return this.request('/contact');
  }

  async createContactMessage(data) {
    return this.request('/contact', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateContactStatus(id, status) {
    return this.request(`/contact/${id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status })
    });
  }

  async deleteContactMessage(id) {
    return this.request(`/contact/${id}`, {
      method: 'DELETE'
    });
  }

  // Courses
  async getCourses() {
    return this.request('/courses');
  }

  async getAllCourses() {
    return this.request('/courses/all');
  }

  async getCourse(id) {
    return this.request(`/courses/${id}`);
  }

  async createCourse(data) {
    return this.request('/courses', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateCourse(id, data) {
    return this.request(`/courses/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteCourse(id) {
    return this.request(`/courses/${id}`, {
      method: 'DELETE'
    });
  }

  async enrollInCourse(courseId, data) {
    return this.request(`/courses/${courseId}/enroll`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async getCourseEnrollments(courseId) {
    return this.request(`/courses/${courseId}/enrollments`);
  }

  async getAllEnrollments() {
    return this.request('/courses/enrollments/all');
  }

  async updateEnrollmentStatus(id, status) {
    return this.request(`/courses/enrollments/${id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status })
    });
  }

  // ============================================
  // COURSE CONTENT (Modules & Lessons)
  // ============================================

  // Modules
  async getCourseModules(courseId) {
    return this.request(`/content/courses/${courseId}/modules`);
  }

  async getModule(moduleId) {
    return this.request(`/content/modules/${moduleId}`);
  }

  async createModule(courseId, data) {
    return this.request(`/content/courses/${courseId}/modules`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateModule(moduleId, data) {
    return this.request(`/content/modules/${moduleId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteModule(moduleId) {
    return this.request(`/content/modules/${moduleId}`, {
      method: 'DELETE'
    });
  }

  async reorderModules(courseId, moduleIds) {
    return this.request(`/content/courses/${courseId}/modules/reorder`, {
      method: 'PUT',
      body: JSON.stringify({ moduleIds })
    });
  }

  // Lessons
  async getModuleLessons(moduleId) {
    return this.request(`/content/modules/${moduleId}/lessons`);
  }

  async getLesson(lessonId) {
    return this.request(`/content/lessons/${lessonId}`);
  }

  async createLesson(moduleId, data) {
    return this.request(`/content/modules/${moduleId}/lessons`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async updateLesson(lessonId, data) {
    return this.request(`/content/lessons/${lessonId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteLesson(lessonId) {
    return this.request(`/content/lessons/${lessonId}`, {
      method: 'DELETE'
    });
  }

  async reorderLessons(moduleId, lessonIds) {
    return this.request(`/content/modules/${moduleId}/lessons/reorder`, {
      method: 'PUT',
      body: JSON.stringify({ lessonIds })
    });
  }

  // Student Progress
  async markLessonComplete(lessonId) {
    return this.request(`/content/lessons/${lessonId}/complete`, {
      method: 'POST'
    });
  }

  async getCourseProgress(courseId) {
    return this.request(`/content/courses/${courseId}/progress`);
  }

  // Check if module is unlocked for student
  async checkModuleUnlocked(moduleId) {
    return this.request(`/content/modules/${moduleId}/unlocked`);
  }

  // Quiz
  async getModuleQuiz(moduleId) {
    return this.request(`/content/modules/${moduleId}/quiz`);
  }

  async submitQuizAttempt(moduleId, answers, timeTaken) {
    return this.request(`/content/modules/${moduleId}/quiz/submit`, {
      method: 'POST',
      body: JSON.stringify({
        answers: answers,
        time_taken_seconds: timeTaken
      })
    });
  }

  // Admin
  async getDashboardStats() {
    return this.request('/admin/dashboard/stats');
  }

  async getUsers() {
    return this.request('/admin/users');
  }

  async updateUserRole(id, role) {
    return this.request(`/admin/users/${id}/role`, {
      method: 'PUT',
      body: JSON.stringify({ role })
    });
  }

  async deleteUser(id) {
    return this.request(`/admin/users/${id}`, {
      method: 'DELETE'
    });
  }

  // Quiz Management (Admin)
  async createModuleQuiz(moduleId, data) {
    return this.request(`/quiz-management/modules/${moduleId}/quiz`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async getModuleQuizAdmin(moduleId) {
    return this.request(`/quiz-management/modules/${moduleId}/quiz`);
  }

  async updateQuiz(quizId, data) {
    return this.request(`/quiz-management/quizzes/${quizId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteQuiz(quizId) {
    return this.request(`/quiz-management/quizzes/${quizId}`, {
      method: 'DELETE'
    });
  }

  async createQuizQuestion(quizId, data) {
    return this.request(`/quiz-management/quizzes/${quizId}/questions`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async getQuizQuestions(quizId) {
    return this.request(`/quiz-management/quizzes/${quizId}/questions`);
  }

  async updateQuizQuestion(questionId, data) {
    return this.request(`/quiz-management/questions/${questionId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteQuizQuestion(questionId) {
    return this.request(`/quiz-management/questions/${questionId}`, {
      method: 'DELETE'
    });
  }

  async createQuestionAnswer(questionId, data) {
    return this.request(`/quiz-management/questions/${questionId}/answers`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  async getQuestionAnswers(questionId) {
    return this.request(`/quiz-management/questions/${questionId}/answers`);
  }

  async updateQuestionAnswer(answerId, data) {
    return this.request(`/quiz-management/answers/${answerId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  async deleteQuestionAnswer(answerId) {
    return this.request(`/quiz-management/answers/${answerId}`, {
      method: 'DELETE'
    });
  }

  // ============================================
  // CERTIFICATES
  // ============================================

  // Get student's certificates
  async getMyCertificates() {
    return this.request('/certificates/my-certificates');
  }

  // Check eligibility for certificate
  async checkCertificateEligibility(courseId) {
    return this.request(`/certificates/check-eligibility/${courseId}`);
  }

  // Generate certificate
  async generateCertificate(courseId) {
    return this.request(`/certificates/generate/${courseId}`, {
      method: 'POST'
    });
  }

  // Get certificate by ID
  async getCertificate(certificateId) {
    return this.request(`/certificates/${certificateId}`);
  }

  // Verify certificate (public)
  async verifyCertificate(verificationCode) {
    return this.request(`/certificates/verify/${verificationCode}`);
  }

  // ============================================
  // FINAL QUIZ MANAGEMENT (Admin)
  // ============================================

  // Create final quiz for course
  async createCourseFinalQuiz(courseId, data) {
    return this.request(`/quiz-management/courses/${courseId}/final-quiz`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }

  // Get final quiz for course (student route)
  async getCourseFinalQuiz(courseId) {
    // Use student-accessible route
    return this.request(`/content/courses/${courseId}/final-quiz`);
  }

  // Get final quiz for course (admin route)
  async getCourseFinalQuizAdmin(courseId) {
    // Use admin route
    return this.request(`/quiz-management/courses/${courseId}/final-quiz`);
  }

  // Update final quiz (admin only)
  async updateFinalQuiz(quizId, data) {
    return this.request(`/quiz-management/final-quizzes/${quizId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    });
  }

  // Delete final quiz (admin only)
  async deleteFinalQuiz(quizId) {
    return this.request(`/quiz-management/final-quizzes/${quizId}`, {
      method: 'DELETE'
    });
  }

  // Get questions for final quiz
  async getFinalQuizQuestions(quizId) {
    // Use student-accessible route instead of admin route
    return this.request(`/content/final-quizzes/${quizId}/questions`);
  }

  // Submit final quiz attempt (student)
  async submitFinalQuizAttempt(quizId, answers) {
    return this.request(`/content/final-quizzes/${quizId}/submit`, {
      method: 'POST',
      body: JSON.stringify({ answers })
    });
  }

  // Create question for final quiz
  async createFinalQuizQuestion(quizId, data) {
    return this.request(`/quiz-management/final-quizzes/${quizId}/questions`, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }
}

const api = new APIClient();
