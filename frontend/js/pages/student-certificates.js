// ============================================
// STUDENT CERTIFICATES PAGE
// ============================================

let certificates = [];
let eligibleCourses = [];

document.addEventListener('DOMContentLoaded', async () => {
  // Check authentication
  if (!api.token) {
    window.location.href = 'student-login.html';
    return;
  }

  await loadCertificates();
  await loadEligibleCourses();
});

// ============================================
// LOAD CERTIFICATES
// ============================================

async function loadCertificates() {
  try {
    const response = await api.getMyCertificates();
    console.log('Certificates API response:', response);
    certificates = response.data || [];
    console.log('Loaded certificates:', certificates);

    console.log('About to call renderCertificates()...');
    renderCertificates();
    console.log('renderCertificates() completed');
  } catch (error) {
    console.error('Error loading certificates:', error);
    showError('Erreur lors du chargement des certificats');
  }
}

function renderCertificates() {
  const container = document.getElementById('certificates-list');
  console.log('renderCertificates called, container:', container);
  console.log('certificates.length:', certificates.length);

  if (!container) {
    console.error('Container #certificates-list not found!');
    return;
  }

  if (certificates.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"></div>
        <h3>Aucun certificat pour le moment</h3>
        <p>Complétez un cours à 100% pour obtenir votre certificat</p>
      </div>
    `;
    return;
  }

  try {
    const html = certificates.map(cert => {
      console.log('Creating card for cert:', cert);
      return createCertificateCard(cert);
    }).join('');
    console.log('Generated HTML length:', html.length);
    container.innerHTML = html;
    console.log('HTML set successfully');
  } catch (error) {
    console.error('Error rendering certificates:', error);
    container.innerHTML = `<div class="error-state">Erreur lors de l'affichage des certificats: ${error.message}</div>`;
  }
}

function createCertificateCard(cert) {
  console.log('Creating card for certificate:', cert);

  try {
    const issueDate = formatDate(cert.issue_date);
    const expiryDate = cert.expiry_date ? formatDate(cert.expiry_date) : 'Permanent';
    const isExpired = cert.expiry_date && new Date(cert.expiry_date) < new Date();

    return `
      <div class="certificate-card">
        <div class="certificate-icon">
          <div class="certificate-seal">
            <span>✓</span>
          </div>
        </div>
        <div class="certificate-details">
          <h3 class="certificate-course">${cert.course_title || 'Titre non disponible'}</h3>
          <div class="certificate-meta">
            <span class="certificate-number">N° ${cert.certificate_number || 'N/A'}</span>
            ${isExpired ? '<span class="badge badge-warning">Expiré</span>' : '<span class="badge badge-success">Valide</span>'}
          </div>
          <div class="certificate-dates">
            <div class="date-item">
              <span class="date-label">Délivré le:</span>
              <span class="date-value">${issueDate}</span>
            </div>
            <div class="date-item">
              <span class="date-label">Valide jusqu'au:</span>
              <span class="date-value">${expiryDate}</span>
            </div>
          </div>
          <div class="verification-info">
            <span class="verification-label">Code de vérification:</span>
            <span class="verification-code">${cert.verification_code || 'N/A'}</span>
          </div>
        </div>
        <div class="certificate-actions">
          <button class="btn btn-primary" onclick="viewCertificate(${cert.id})">
            Voir le certificat
          </button>
          <button class="btn btn-ghost" onclick="downloadCertificate(${cert.id})">
            ⬇️ Télécharger PDF
          </button>
          <button class="btn btn-ghost btn-sm" onclick="shareCertificate('${cert.verification_code}')">
            Partager
          </button>
        </div>
      </div>
    `;
  } catch (error) {
    console.error('Error creating certificate card:', error, cert);
    throw error;
  }
}

// ============================================
// LOAD ELIGIBLE COURSES
// ============================================

async function loadEligibleCourses() {
  try {
    const enrollmentsResponse = await api.getStudentEnrollments();
    const enrollments = enrollmentsResponse.data || [];

    // Check which courses are eligible for certificates
    const eligibilityChecks = enrollments.map(async (enrollment) => {
      try {
        const response = await api.checkCertificateEligibility(enrollment.course_id);
        if (response.data.eligible) {
          return {
            ...enrollment,
            eligible: true
          };
        }
      } catch (error) {
        console.error(`Error checking eligibility for course ${enrollment.course_id}:`, error);
      }
      return null;
    });

    const results = await Promise.all(eligibilityChecks);
    eligibleCourses = results.filter(course => course !== null);

    renderEligibleCourses();
  } catch (error) {
    console.error('Error loading eligible courses:', error);
  }
}

function renderEligibleCourses() {
  const container = document.getElementById('eligible-courses');

  if (eligibleCourses.length === 0) {
    container.innerHTML = `
      <div class="info-message">
        <p>Aucun cours éligible pour le moment. Complétez vos cours à 100% pour obtenir vos certificats.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="eligible-courses-list">
      ${eligibleCourses.map(course => `
        <div class="eligible-course-card">
          <div class="course-info">
            <h4>${course.course_title}</h4>
            <p class="success-text">✓ Vous avez complété ce cours à 100%</p>
          </div>
          <button class="btn btn-success" onclick="generateCertificate(${course.course_id})">
             Générer mon certificat
          </button>
        </div>
      `).join('')}
    </div>
  `;
}

// ============================================
// CERTIFICATE ACTIONS
// ============================================

async function generateCertificate(courseId) {
  if (!confirm('Voulez-vous générer votre certificat pour ce cours ?')) {
    return;
  }

  try {
    const response = await api.generateCertificate(courseId);
    showSuccess('Certificat généré avec succès !');

    // Reload certificates
    await loadCertificates();
    await loadEligibleCourses();
  } catch (error) {
    console.error('Error generating certificate:', error);
    showError(error.message || 'Erreur lors de la génération du certificat');
  }
}

function viewCertificate(certificateId) {
  window.location.href = `certificate-view.html?id=${certificateId}`;
}

function downloadCertificate(certificateId) {
  // For now, redirect to view page
  // In future, generate and download PDF
  alert('Fonctionnalité de téléchargement PDF en cours de développement.\nPour le moment, vous pouvez visualiser et imprimer votre certificat.');
  viewCertificate(certificateId);
}

function shareCertificate(verificationCode) {
  const verifyUrl = `${window.location.origin}/pages/verify-certificate.html?code=${verificationCode}`;

  if (navigator.clipboard) {
    navigator.clipboard.writeText(verifyUrl).then(() => {
      showSuccess('Lien de vérification copié dans le presse-papiers !');
    }).catch(() => {
      prompt('Copiez ce lien pour partager votre certificat:', verifyUrl);
    });
  } else {
    prompt('Copiez ce lien pour partager votre certificat:', verifyUrl);
  }
}

// ============================================
// UTILITIES
// ============================================

function formatDate(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  });
}

function showSuccess(message) {
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
