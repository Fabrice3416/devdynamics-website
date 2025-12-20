// ============================================
// BLOG PAGE SCRIPT
// ============================================

let currentPage = 1;
const postsPerPage = 9;
let allPosts = [];

document.addEventListener('DOMContentLoaded', async () => {
  initNavigation();
  loadBlogPosts();
  loadCategories();
  loadRecentPosts();
  setupSearchForm();
});

// Navigation
function initNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');
  const navLinks = document.querySelectorAll('.nav-link');

  hamburger.addEventListener('click', () => {
    navMenu.classList.toggle('active');
  });

  navLinks.forEach(link => {
    link.addEventListener('click', () => {
      navMenu.classList.remove('active');
    });
  });
}

// Load blog posts
async function loadBlogPosts() {
  try {
    const response = await api.getBlogPosts(currentPage, postsPerPage);
    if (response.success) {
      allPosts = response.data;
      renderBlogPosts(response.data);
      renderPagination(response.pagination);
    }
  } catch (error) {
    console.error('Erreur chargement blog:', error);
  }
}

function renderBlogPosts(posts) {
  const container = document.getElementById('blog-posts');
  container.innerHTML = '';

  if (posts.length === 0) {
    container.innerHTML = '<p class="text-center text-gray">Aucun article trouvé</p>';
    return;
  }

  posts.forEach(post => {
    const card = document.createElement('article');
    card.className = 'blog-card';
    card.innerHTML = `
      <div class="blog-card-image">
        ${post.featured_image
          ? `<img src="${post.featured_image}" alt="${post.title}" onerror="this.parentElement.innerHTML='📝'">`
          : '📝'}
      </div>
      <div class="blog-card-content">
        ${post.category ? `<span class="badge badge-primary blog-card-category">${post.category}</span>` : ''}
        <h2 class="blog-card-title">${post.title}</h2>
        <p class="blog-card-excerpt">${truncate(post.excerpt || post.content, 150)}</p>
        <div class="blog-card-meta">
          <span>${formatDate(post.published_at)}</span>
          <a href="blog-post.html?slug=${post.slug}" class="blog-card-link">Lire →</a>
        </div>
      </div>
    `;
    container.appendChild(card);
  });
}

function renderPagination(pagination) {
  const container = document.getElementById('pagination');
  container.innerHTML = '';

  // Previous button
  if (pagination.page > 1) {
    const prevBtn = document.createElement('a');
    prevBtn.href = '#';
    prevBtn.textContent = '← Précédent';
    prevBtn.addEventListener('click', (e) => {
      e.preventDefault();
      currentPage--;
      loadBlogPosts();
      scrollToElement('#blog-posts');
    });
    container.appendChild(prevBtn);
  }

  // Page numbers
  for (let i = 1; i <= pagination.pages; i++) {
    const pageBtn = document.createElement('button');
    pageBtn.textContent = i;
    pageBtn.className = i === pagination.page ? 'active' : '';
    pageBtn.addEventListener('click', () => {
      currentPage = i;
      loadBlogPosts();
      scrollToElement('#blog-posts');
    });
    container.appendChild(pageBtn);
  }

  // Next button
  if (pagination.page < pagination.pages) {
    const nextBtn = document.createElement('a');
    nextBtn.href = '#';
    nextBtn.textContent = 'Suivant →';
    nextBtn.addEventListener('click', (e) => {
      e.preventDefault();
      currentPage++;
      loadBlogPosts();
      scrollToElement('#blog-posts');
    });
    container.appendChild(nextBtn);
  }
}

// Load categories
async function loadCategories() {
  try {
    const response = await api.getBlogPosts(1, 100);
    if (response.success) {
      const categories = [...new Set(response.data.map(post => post.category).filter(Boolean))];
      const container = document.getElementById('categories-list');
      container.innerHTML = '';

      categories.forEach(category => {
        const li = document.createElement('li');
        li.innerHTML = `<a href="#" onclick="filterByCategory('${category}')">${category}</a>`;
        container.appendChild(li);
      });
    }
  } catch (error) {
    console.error('Erreur chargement catégories:', error);
  }
}

function filterByCategory(category) {
  const filtered = allPosts.filter(post => post.category === category);
  renderBlogPosts(filtered);
}

// Load recent posts
async function loadRecentPosts() {
  try {
    const response = await api.getBlogPosts(1, 5);
    if (response.success) {
      const container = document.getElementById('recent-posts');
      container.innerHTML = '';

      response.data.forEach(post => {
        const li = document.createElement('li');
        li.innerHTML = `
          <a href="blog-post.html?slug=${post.slug}">${post.title}</a>
          <div class="recent-posts-date">${formatDate(post.published_at)}</div>
        `;
        container.appendChild(li);
      });
    }
  } catch (error) {
    console.error('Erreur chargement articles récents:', error);
  }
}

// Search
function setupSearchForm() {
  const form = document.getElementById('search-form');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const query = document.getElementById('search-input').value.toLowerCase();
    const filtered = allPosts.filter(post =>
      post.title.toLowerCase().includes(query) ||
      post.content.toLowerCase().includes(query)
    );
    renderBlogPosts(filtered);
  });
}
