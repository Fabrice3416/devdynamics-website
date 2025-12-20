// ============================================
// ADMIN DASHBOARD SCRIPT
// ============================================

// CHECK AUTHENTICATION IMMEDIATELY (before page renders)
(function() {
  const user = getStorage('user');

  // Redirect immediately if not authenticated or not authorized
  if (!localStorage.getItem('auth_token') || !user || (user.role !== 'admin' && user.role !== 'instructor')) {
    window.location.href = '../index.html';
    // Stop script execution
    throw new Error('Access denied - redirecting');
  }
})();

let currentPage = 'dashboard';
let editingId = null;

document.addEventListener('DOMContentLoaded', async () => {
  // Double-check authentication (defensive programming)
  if (!api.token) {
    window.location.href = 'admin-login.html';
    return;
  }

  const user = getStorage('user');

  // Double-check authorization
  if (!user || (user.role !== 'admin' && user.role !== 'instructor')) {
    window.location.href = '../index.html';
    return;
  }

  if (user) {
    document.getElementById('user-name').textContent = user.full_name;
  }

  // Initialize
  initNavigation();
  initMobileMenu();
  initLogout();
  loadDashboard();
  setupFormHandlers();
});

// Navigation
function initNavigation() {
  const menuItems = document.querySelectorAll('.menu-item[data-page]');
  
  menuItems.forEach(item => {
    item.addEventListener('click', (e) => {
      e.preventDefault();
      const page = item.dataset.page;
      switchPage(page);
    });
  });

  const quickLinks = document.querySelectorAll('.quick-link[data-page]');
  quickLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const page = link.dataset.page;
      switchPage(page);
    });
  });
}

function switchPage(page) {
  // Hide all pages
  document.querySelectorAll('.page-content').forEach(p => {
    p.classList.remove('active');
  });

  // Show selected page
  const pageElement = document.getElementById(`${page}-page`);
  if (pageElement) {
    pageElement.classList.add('active');
  }

  // Update menu
  document.querySelectorAll('.menu-item').forEach(item => {
    item.classList.remove('active');
  });
  document.querySelector(`[data-page="${page}"]`)?.classList.add('active');

  // Update title
  const titles = {
    dashboard: 'Tableau de bord',
    programs: 'Programmes',
    testimonials: 'Témoignages',
    blog: 'Articles de Blog',
    donations: 'Dons',
    contact: 'Messages de Contact',
    courses: 'Gestion des Cours',
    sponsors: 'Sponsors',
    organization: 'Informations de l\'Organisation',
    users: 'Utilisateurs'
  };
  document.getElementById('page-title').textContent = titles[page] || 'Page';

  // Load page data
  loadPageData(page);

  currentPage = page;
}

async function loadPageData(page) {
  switch(page) {
    case 'programs':
      loadPrograms();
      break;
    case 'testimonials':
      loadTestimonials();
      break;
    case 'sponsors':
      loadSponsors();
      break;
    case 'blog':
      loadBlogPosts();
      break;
    case 'donations':
      loadDonations();
      break;
    case 'contact':
      loadContactMessages();
      break;
    case 'courses':
      loadCourses();
      break;
    case 'organization':
      loadOrganizationForm();
      break;
    case 'users':
      loadUsers();
      break;
  }
}

// Dashboard
async function loadDashboard() {
  try {
    const response = await api.getDashboardStats();
    if (response.success) {
      const stats = response.data;

      // Update stats with data from backend
      if (document.getElementById('stat-donations')) {
        document.getElementById('stat-donations').textContent = stats.donations?.total?.total || '0 FCFA';
      }
      if (document.getElementById('stat-contacts')) {
        document.getElementById('stat-contacts').textContent = stats.contacts?.unread?.count || 0;
      }
      if (document.getElementById('stat-sponsors')) {
        document.getElementById('stat-sponsors').textContent = stats.sponsors?.total?.count || 0;
      }
      if (document.getElementById('stat-programs')) {
        document.getElementById('stat-programs').textContent = stats.programs?.total?.count || 0;
      }
      if (document.getElementById('stat-courses')) {
        document.getElementById('stat-courses').textContent = stats.courses?.total?.count || 0;
      }
      if (document.getElementById('stat-students')) {
        document.getElementById('stat-students').textContent = stats.students?.total?.count || 0;
      }
      if (document.getElementById('stat-enrollments')) {
        document.getElementById('stat-enrollments').textContent = stats.enrollments?.total?.count || 0;
      }
      if (document.getElementById('stat-blog')) {
        document.getElementById('stat-blog').textContent = stats.blog?.published?.count || 0;
      }

      console.log('Dashboard stats loaded:', stats);
    }
  } catch (error) {
    console.error('Erreur chargement stats:', error);
  }
}

// Programs
async function loadPrograms() {
  try {
    const response = await api.getPrograms();
    if (response.success) {
      const container = document.getElementById('programs-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Titre</th>
              <th>Catégorie</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(program => `
              <tr>
                <td>${program.title}</td>
                <td>${program.category || '-'}</td>
                <td><span class="badge badge-primary">${program.is_active ? 'Actif' : 'Inactif'}</span></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="editProgram(${program.id})">Modifier</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteProgram(${program.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement programmes:', error);
  }
}

function editProgram(id) {
  editingId = id;
  openModal('program-modal');
}

async function deleteProgram(id) {
  if (confirm('Êtes-vous sûr?')) {
    try {
      await api.deleteProgram(id);
      showNotification('Programme supprimé', 'success');
      loadPrograms();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

// Testimonials
async function loadTestimonials() {
  try {
    const response = await api.getTestimonials();
    if (response.success) {
      const container = document.getElementById('testimonials-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Titre</th>
              <th>Vidéo</th>
              <th>Note</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(testimonial => `
              <tr>
                <td>${testimonial.student_name}</td>
                <td>${testimonial.student_title || '-'}</td>
                <td>${testimonial.video_url ? `<a href="${testimonial.video_url}" target="_blank" style="color: var(--color-primary);"><i class="ti ti-video"></i> Voir</a>` : '-'}</td>
                <td>${testimonial.rating ? '<i class="ti ti-star-filled" style="color: var(--color-warning);"></i>'.repeat(testimonial.rating) : '-'}</td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="editTestimonial(${testimonial.id})">Modifier</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteTestimonial(${testimonial.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement témoignages:', error);
  }
}

async function deleteTestimonial(id) {
  if (confirm('Êtes-vous sûr?')) {
    try {
      await api.deleteTestimonial(id);
      showNotification('Témoignage supprimé', 'success');
      loadTestimonials();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

// Sponsors
async function loadSponsors() {
  try {
    const response = await api.getAllSponsors();
    if (response.success) {
      const container = document.getElementById('sponsors-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Logo</th>
              <th>Nom</th>
              <th>Niveau</th>
              <th>Site web</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(sponsor => `
              <tr>
                <td><img src="${sponsor.logo_url}" alt="${sponsor.name}" style="height: 40px; max-width: 80px; object-fit: contain;" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Crect fill=%27%23e0e0e0%27 width=%27100%27 height=%27100%27/%3E%3Ctext x=%2750%27 y=%2755%27 text-anchor=%27middle%27 fill=%27%23999%27 font-size=%2714%27%3ELogo%3C/text%3E%3C/svg%3E'"></td>
                <td>${sponsor.name}</td>
                <td>${getTierBadge(sponsor.tier)}</td>
                <td>${sponsor.website_url ? `<a href="${sponsor.website_url}" target="_blank" style="color: var(--color-primary);"><i class="ti ti-link"></i> Site</a>` : '-'}</td>
                <td><span class="badge ${sponsor.is_active ? 'badge-primary' : 'badge-secondary'}">${sponsor.is_active ? 'Actif' : 'Inactif'}</span></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="editSponsor(${sponsor.id})">Modifier</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteSponsor(${sponsor.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement sponsors:', error);
  }
}

function getTierBadge(tier) {
  const badges = {
    platinum: '<i class="ti ti-diamond"></i> Platine',
    gold: '<i class="ti ti-medal-2"></i> Or',
    silver: '<i class="ti ti-medal"></i> Argent',
    bronze: '🥉 Bronze'
  };
  return badges[tier] || tier;
}

async function deleteSponsor(id) {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce sponsor?')) {
    try {
      await api.deleteSponsor(id);
      showNotification('Sponsor supprimé', 'success');
      loadSponsors();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

// Blog
async function loadBlogPosts() {
  try {
    const response = await api.getBlogPosts(1, 100);
    if (response.success) {
      const container = document.getElementById('blog-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Titre</th>
              <th>Catégorie</th>
              <th>Publié</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(post => `
              <tr>
                <td>${post.title}</td>
                <td>${post.category || '-'}</td>
                <td><span class="badge badge-primary">${post.is_published ? 'Oui' : 'Non'}</span></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="editBlogPost(${post.id})">Modifier</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteBlogPost(${post.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement blog:', error);
  }
}

async function deleteBlogPost(id) {
  if (confirm('Êtes-vous sûr?')) {
    try {
      await api.deleteBlogPost(id);
      showNotification('Article supprimé', 'success');
      loadBlogPosts();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

async function editBlogPost(id) {
  try {
    // Get all blog posts and find the one we want
    const response = await api.getBlogPosts(1, 100);
    const post = response.data.find(p => p.id === id);

    if (!post) {
      showNotification('Article non trouvé', 'error');
      return;
    }

    // Populate form
    document.getElementById('blog-title').value = post.title || '';
    document.getElementById('blog-slug').value = post.slug || '';
    document.getElementById('blog-excerpt').value = post.excerpt || '';
    document.getElementById('blog-content').value = post.content || '';
    document.getElementById('blog-category').value = post.category || '';
    document.getElementById('blog-featured-image').value = post.featured_image || '';
    document.getElementById('blog-is-published').checked = post.is_published || false;

    // Update modal title and set editing ID
    document.getElementById('blog-modal-title').textContent = 'Modifier l\'article';
    editingId = id;

    openModal('blog-modal');
  } catch (error) {
    console.error('Error loading blog post:', error);
    showNotification('Erreur lors du chargement de l\'article', 'error');
  }
}

async function handleBlogSubmit(e) {
  e.preventDefault();

  const formData = getFormData(e.target);

  // Convert checkbox to boolean
  formData.is_published = document.getElementById('blog-is-published').checked;

  // Debug: log user info
  const user = getStorage('user');
  console.log('Current user:', user);
  console.log('User role:', user?.role);
  console.log('Has token:', !!api.token);

  try {
    if (editingId) {
      await api.updateBlogPost(editingId, formData);
      showNotification('Article mis à jour avec succès', 'success');
    } else {
      await api.createBlogPost(formData);
      showNotification('Article créé avec succès', 'success');
    }

    closeModal('blog-modal');
    loadBlogPosts();
    editingId = null;
  } catch (error) {
    console.error('Error saving blog post:', error);
    console.error('Error details:', error.message);

    // Show more detailed error message
    let errorMessage = error.message || 'Erreur lors de l\'enregistrement';
    if (error.message?.includes('Accès refusé') || error.message?.includes('administrateur')) {
      errorMessage = 'Erreur: Vous n\'avez pas les permissions nécessaires. Veuillez vous reconnecter.';
    }
    showNotification(errorMessage, 'error');
  }
}

// Donations
async function loadDonations() {
  try {
    const response = await api.getDonations();
    if (response.success) {
      const container = document.getElementById('donations-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Donateur</th>
              <th>Montant</th>
              <th>Statut</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(donation => `
              <tr>
                <td>${donation.donor_name}</td>
                <td>${formatCurrency(donation.amount, donation.currency)}</td>
                <td><span class="badge badge-primary">${donation.status}</span></td>
                <td>${formatDate(donation.created_at)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement dons:', error);
  }
}

// Contact Messages
async function loadContactMessages() {
  try {
    const response = await api.getContactMessages();
    if (response.success) {
      const container = document.getElementById('contact-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Email</th>
              <th>Sujet</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(message => `
              <tr>
                <td>${message.name}</td>
                <td>${message.email}</td>
                <td>${message.subject}</td>
                <td><span class="badge badge-primary">${message.status}</span></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="viewMessage(${message.id})">Voir</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteContactMessage(${message.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement messages:', error);
  }
}

async function deleteContactMessage(id) {
  if (confirm('Êtes-vous sûr?')) {
    try {
      await api.deleteContactMessage(id);
      showNotification('Message supprimé', 'success');
      loadContactMessages();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

// Courses
let currentCourseId = null;

async function loadCourses() {
  try {
    const response = await api.getAllCourses();
    if (response.success) {
      const container = document.getElementById('courses-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Titre</th>
              <th>Niveau</th>
              <th>Durée</th>
              <th>Prix</th>
              <th>Inscriptions</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(course => {
              const courseTypeLabel = {
                'online': '💻 En ligne',
                'physical': '📍 Présentiel'
              };
              return `
              <tr>
                <td>
                  ${course.title}
                  ${course.course_type ? `<br><small style="color: var(--color-text-light);">${courseTypeLabel[course.course_type] || course.course_type}</small>` : ''}
                </td>
                <td>${course.level}</td>
                <td>${course.duration || '-'}</td>
                <td>${course.price ? course.price + ' USD' : 'Gratuit'}</td>
                <td><button class="btn btn-ghost btn-sm" onclick="viewEnrollments(${course.id})">Voir (0)</button></td>
                <td><span class="badge ${course.is_active ? 'badge-success' : 'badge-secondary'}">${course.is_active ? 'Actif' : 'Inactif'}</span></td>
                <td>
                  <div class="action-buttons">
                    ${course.course_type === 'online' ? `<button class="btn btn-primary btn-sm" onclick="manageCourseContent(${course.id})">Gérer le contenu</button>` : ''}
                    <button class="btn btn-primary btn-sm" onclick="editCourse(${course.id})">Modifier</button>
                    <button class="btn ${course.is_active ? 'btn-ghost' : 'btn-success'} btn-sm" onclick="toggleCourseStatus(${course.id}, ${!course.is_active})">${course.is_active ? 'Désactiver' : 'Activer'}</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteCourse(${course.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
  }
}

// Setup course form
document.getElementById('add-course-btn')?.addEventListener('click', () => {
  currentCourseId = null;
  document.getElementById('course-form').reset();
  openModal('course-modal');
});

document.getElementById('course-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = getFormData(e.target);

  // Debug: Log form data to check course_type value
  console.log('Form data being sent:', formData);
  console.log('Course type value:', formData.course_type);

  try {
    if (currentCourseId) {
      await api.updateCourse(currentCourseId, {...formData, is_active: true});
      showNotification('Cours mis à jour avec succès', 'success');
    } else {
      await api.createCourse(formData);
      showNotification('Cours créé avec succès', 'success');
    }
    closeModal('course-modal');
    loadCourses();
  } catch (error) {
    showNotification('Erreur lors de l\'enregistrement', 'error');
  }
});

window.editCourse = async function(id) {
  try {
    const response = await api.getCourse(id);
    if (response.success) {
      const course = response.data;
      currentCourseId = id;

      document.getElementById('course-title').value = course.title || '';
      document.getElementById('course-description').value = course.description || '';
      document.getElementById('course-type').value = course.course_type || 'physical';
      document.getElementById('course-duration').value = course.duration || '';
      document.getElementById('course-level').value = course.level || 'debutant';
      document.getElementById('course-instructor').value = course.instructor || '';
      document.getElementById('course-price').value = course.price || 0;
      document.getElementById('course-max-students').value = course.max_students || 30;
      document.getElementById('course-start-date').value = course.start_date ? course.start_date.split('T')[0] : '';
      document.getElementById('course-end-date').value = course.end_date ? course.end_date.split('T')[0] : '';
      document.getElementById('course-schedule').value = course.schedule || '';
      document.getElementById('course-category').value = course.category || '';
      document.getElementById('course-thumbnail').value = course.thumbnail_url || '';
      document.getElementById('course-intro-video').value = course.intro_video_url || '';

      // Trigger course type change to show/hide appropriate fields
      document.getElementById('course-type').dispatchEvent(new Event('change'));

      openModal('course-modal');
    }
  } catch (error) {
    showNotification('Erreur lors du chargement', 'error');
  }
}

window.toggleCourseStatus = async function(id, newStatus) {
  try {
    const course = await api.getCourse(id);
    if (course.success) {
      await api.updateCourse(id, { ...course.data, is_active: newStatus });
      showNotification(`Cours ${newStatus ? 'activé' : 'désactivé'} avec succès`, 'success');
      loadCourses();
    }
  } catch (error) {
    showNotification('Erreur lors de la mise à jour', 'error');
  }
}

window.deleteCourse = async function(id) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer ce cours ?')) return;

  try {
    await api.deleteCourse(id);
    showNotification('Cours supprimé avec succès', 'success');
    loadCourses();
  } catch (error) {
    showNotification('Erreur lors de la suppression', 'error');
  }
}

window.viewEnrollments = async function(courseId) {
  try {
    const response = await api.getCourseEnrollments(courseId);
    if (response.success) {
      const enrollments = response.data;
      const container = document.getElementById('courses-list');
      container.innerHTML = `
        <div class="mb-lg">
          <button class="btn btn-ghost" onclick="loadCourses()">← Retour aux cours</button>
        </div>
        <h3>Inscriptions au cours</h3>
        <table>
          <thead>
            <tr>
              <th>Étudiant</th>
              <th>Email</th>
              <th>Téléphone</th>
              <th>Statut</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${enrollments.length === 0 ? '<tr><td colspan="6">Aucune inscription</td></tr>' : enrollments.map(enrollment => `
              <tr>
                <td>${enrollment.student_name}</td>
                <td>${enrollment.student_email}</td>
                <td>${enrollment.student_phone || '-'}</td>
                <td><span class="badge badge-${enrollment.status === 'approved' ? 'success' : enrollment.status === 'rejected' ? 'secondary' : 'primary'}">${enrollment.status}</span></td>
                <td>${formatDate(enrollment.created_at)}</td>
                <td>
                  <div class="action-buttons">
                    ${enrollment.status === 'pending' ? `
                      <button class="btn btn-primary btn-sm" onclick="approveEnrollment(${enrollment.id})">Approuver</button>
                      <button class="btn btn-secondary btn-sm" onclick="rejectEnrollment(${enrollment.id}, ${courseId})">Rejeter</button>
                    ` : '-'}
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement inscriptions:', error);
  }
}

// Navigate to course content builder
window.manageCourseContent = function(courseId) {
  window.location.href = `admin-course-builder.html?courseId=${courseId}`;
}

async function approveEnrollment(id) {
  try {
    await api.updateEnrollmentStatus(id, 'approved');
    showNotification('Inscription approuvée', 'success');
    // Reload current view
    const courseId = document.querySelector('[onclick*="viewEnrollments"]')?.getAttribute('onclick').match(/\d+/)[0];
    if (courseId) viewEnrollments(courseId);
  } catch (error) {
    showNotification('Erreur', 'error');
  }
}

async function rejectEnrollment(id, courseId) {
  try {
    await api.updateEnrollmentStatus(id, 'rejected');
    showNotification('Inscription rejetée', 'success');
    viewEnrollments(courseId);
  } catch (error) {
    showNotification('Erreur', 'error');
  }
}

// Organization
async function loadOrganizationForm() {
  try {
    const response = await api.getOrganizationInfo();
    if (response.success) {
      const org = response.data;
      document.getElementById('org-name').value = org.name || '';
      document.getElementById('org-email').value = org.email || '';
      document.getElementById('org-phone').value = org.phone || '';
      document.getElementById('org-whatsapp').value = org.whatsapp_number || '';
      document.getElementById('org-mission').value = org.mission || '';
      document.getElementById('org-vision').value = org.vision || '';
    }
  } catch (error) {
    console.error('Erreur chargement organisation:', error);
  }
}

// Users
async function loadUsers() {
  try {
    const response = await api.getUsers();
    if (response.success) {
      const container = document.getElementById('users-list');
      container.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Email</th>
              <th>Rôle</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${response.data.map(user => `
              <tr>
                <td>${user.full_name}</td>
                <td>${user.email}</td>
                <td><span class="badge badge-primary">${user.role}</span></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="changeUserRole(${user.id})">Modifier rôle</button>
                    <button class="btn btn-secondary btn-sm" onclick="deleteUser(${user.id})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }
  } catch (error) {
    console.error('Erreur chargement utilisateurs:', error);
  }
}

async function deleteUser(id) {
  if (confirm('Êtes-vous sûr?')) {
    try {
      await api.deleteUser(id);
      showNotification('Utilisateur supprimé', 'success');
      loadUsers();
    } catch (error) {
      showNotification('Erreur lors de la suppression', 'error');
    }
  }
}

// Form handlers
function setupFormHandlers() {
  const orgForm = document.getElementById('organization-form');
  if (orgForm) {
    orgForm.addEventListener('submit', handleOrganizationSubmit);
  }

  const programForm = document.getElementById('program-form');
  if (programForm) {
    programForm.addEventListener('submit', handleProgramSubmit);
  }

  const blogForm = document.getElementById('blog-form');
  if (blogForm) {
    blogForm.addEventListener('submit', handleBlogSubmit);
  }

  const testimonialForm = document.getElementById('testimonial-form');
  if (testimonialForm) {
    testimonialForm.addEventListener('submit', handleTestimonialSubmit);
  }

  const sponsorForm = document.getElementById('sponsor-form');
  if (sponsorForm) {
    sponsorForm.addEventListener('submit', handleSponsorSubmit);
  }

  document.getElementById('add-program-btn')?.addEventListener('click', () => {
    editingId = null;
    openModal('program-modal');
  });

  // Blog button - open blog modal
  document.getElementById('add-blog-btn')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('blog-form').reset();
    document.getElementById('blog-modal-title').textContent = 'Ajouter un article de blog';
    openModal('blog-modal');
  });

  // Testimonial button - open testimonial modal
  document.getElementById('add-testimonial-btn')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('testimonial-form').reset();
    openModal('testimonial-modal');
  });

  // Sponsor button - open sponsor modal
  document.getElementById('add-sponsor-btn')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('sponsor-form').reset();
    openModal('sponsor-modal');
  });

  // Auto-generate slug from title
  const blogTitleInput = document.getElementById('blog-title');
  const blogSlugInput = document.getElementById('blog-slug');
  if (blogTitleInput && blogSlugInput) {
    blogTitleInput.addEventListener('input', (e) => {
      // Only auto-generate if slug is empty or was auto-generated
      if (!editingId || blogSlugInput.value === '') {
        const slug = e.target.value
          .toLowerCase()
          .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Remove accents
          .replace(/[^a-z0-9]+/g, '-') // Replace non-alphanumeric with hyphens
          .replace(/^-+|-+$/g, ''); // Remove leading/trailing hyphens
        blogSlugInput.value = slug;
      }
    });
  }

  // Setup course type change handler
  setupCourseTypeLogic();
}

// Course type logic - show/hide fields based on type
function setupCourseTypeLogic() {
  const courseTypeSelect = document.getElementById('course-type');
  if (!courseTypeSelect) return;

  const scheduleField = document.getElementById('course-schedule')?.closest('.form-group');
  const thumbnailGroup = document.getElementById('course-thumbnail-group');
  const introVideoGroup = document.getElementById('course-intro-video-group');

  courseTypeSelect.addEventListener('change', () => {
    const courseType = courseTypeSelect.value;

    if (courseType === 'online') {
      // Online courses: show thumbnail and video fields
      if (thumbnailGroup) thumbnailGroup.style.display = 'block';
      if (introVideoGroup) introVideoGroup.style.display = 'block';

      // Make schedule optional
      if (scheduleField) {
        const label = scheduleField.querySelector('label');
        if (label) label.textContent = 'Horaire (optionnel)';
        scheduleField.style.opacity = '0.7';
      }
    } else if (courseType === 'physical') {
      // Physical courses: hide online-specific fields
      if (thumbnailGroup) thumbnailGroup.style.display = 'none';
      if (introVideoGroup) introVideoGroup.style.display = 'none';

      // Make schedule required
      if (scheduleField) {
        const label = scheduleField.querySelector('label');
        if (label) label.textContent = 'Horaire';
        scheduleField.style.opacity = '1';
      }
    } else {
      // No type selected: hide all optional fields
      if (thumbnailGroup) thumbnailGroup.style.display = 'none';
      if (introVideoGroup) introVideoGroup.style.display = 'none';
      if (scheduleField) {
        const label = scheduleField.querySelector('label');
        if (label) label.textContent = 'Horaire';
        scheduleField.style.opacity = '1';
      }
    }
  });

  // Trigger initial state
  courseTypeSelect.dispatchEvent(new Event('change'));
}

async function handleOrganizationSubmit(e) {
  e.preventDefault();
  const data = getFormData(this);

  try {
    await api.updateOrganizationInfo(data);
    showNotification('Informations mises à jour', 'success');
  } catch (error) {
    showNotification('Erreur lors de la mise à jour', 'error');
  }
}

async function handleProgramSubmit(e) {
  e.preventDefault();
  const data = getFormData(this);

  try {
    if (editingId) {
      await api.updateProgram(editingId, data);
      showNotification('Programme mis à jour', 'success');
    } else {
      await api.createProgram(data);
      showNotification('Programme créé', 'success');
    }
    closeModal('program-modal');
    loadPrograms();
  } catch (error) {
    showNotification('Erreur', 'error');
  }
}

async function handleTestimonialSubmit(e) {
  e.preventDefault();
  const data = getFormData(this);

  try {
    if (editingId) {
      await api.updateTestimonial(editingId, data);
      showNotification('Témoignage mis à jour', 'success');
    } else {
      await api.createTestimonial(data);
      showNotification('Témoignage créé', 'success');
    }
    closeModal('testimonial-modal');
    loadTestimonials();
  } catch (error) {
    console.error('Error saving testimonial:', error);
    showNotification('Erreur lors de la sauvegarde du témoignage', 'error');
  }
}

async function handleSponsorSubmit(e) {
  e.preventDefault();
  const data = getFormData(this);

  try {
    if (editingId) {
      await api.updateSponsor(editingId, data);
      showNotification('Sponsor mis à jour', 'success');
    } else {
      await api.createSponsor(data);
      showNotification('Sponsor créé', 'success');
    }
    closeModal('sponsor-modal');
    loadSponsors();
  } catch (error) {
    console.error('Error saving sponsor:', error);
    showNotification('Erreur lors de la sauvegarde du sponsor', 'error');
  }
}

// Logout
function initLogout() {
  document.getElementById('logout-btn').addEventListener('click', () => {
    api.logout();
    window.location.href = 'admin-login.html';
  });
}

// Mobile Menu
function initMobileMenu() {
  const menuToggle = document.querySelector('.mobile-menu-toggle');
  const sidebar = document.querySelector('.admin-sidebar');

  if (!menuToggle || !sidebar) return;

  menuToggle.addEventListener('click', () => {
    sidebar.classList.toggle('active');
  });

  // Close sidebar when clicking on a menu item (mobile only)
  const menuItems = document.querySelectorAll('.menu-item');
  menuItems.forEach(item => {
    item.addEventListener('click', () => {
      if (window.innerWidth <= 768) {
        sidebar.classList.remove('active');
      }
    });
  });
}
