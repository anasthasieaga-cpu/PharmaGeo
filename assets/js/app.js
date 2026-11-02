// ============================================================
// PharmaGeo — Application principale
// Initialisation globale des interactions du site : tooltips,
// animations, notifications, alertes et gestion de session.
// ============================================================
// ===== PharmaGeo - Application principale =====

document.addEventListener('DOMContentLoaded', function() {
    // Initialisation des tooltips Bootstrap
    if (typeof bootstrap !== 'undefined') {
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));
    }
    
    // Animation au scroll
    const animateOnScroll = () => {
        const elements = document.querySelectorAll('.fade-in, .slide-in-left');
        elements.forEach(el => {
            const rect = el.getBoundingClientRect();
            const isVisible = rect.top < window.innerHeight - 100;
            if (isVisible && !el.classList.contains('animated')) {
                el.classList.add('animated');
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            }
        });
    };
    
    // Initialiser les animations
    document.querySelectorAll('.fade-in, .slide-in-left').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'all 0.6s ease-out';
    });
    
    setTimeout(animateOnScroll, 100);
    window.addEventListener('scroll', animateOnScroll);
    
    // Gestion des notifications : on rafraîchit le compteur de la cloche
    // s'il y a quelqu'un de connecté, même si aucune pastille n'est affichée.
    if (document.getElementById('notificationsDropdown')) {
        checkNotifications();
    }
    
    // Confirmation de suppression
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            const message = this.dataset.confirm || 'Êtes-vous sûr ?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-dismiss des alertes
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) closeBtn.click();
        }, 5000);
    });
});

// ===== Fonctions utilitaires =====

// Vérifier les notifications et rafraîchir la pastille de la cloche.
function checkNotifications() {
    const lien = document.getElementById('notificationsDropdown');
    if (!lien) return;

    fetch(window.BASE_URL + 'api/notifications.php', { credentials: 'same-origin' })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            if (!data) return;

            const total = parseInt(data.unread, 10) || 0;
            let badge = lien.querySelector('.notification-badge');

            if (total > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge';
                    badge.style.fontSize = '0.6rem';
                    lien.appendChild(badge);
                }
                badge.textContent = data.label;
                badge.dataset.unread = total;
            } else if (badge) {
                badge.remove();
            }
        })
        .catch(error => console.error('Erreur notifications:', error));
}

// Formater une distance exprimée en mètres.
// Mêmes règles que geoFormaterDistance() (includes/geo.php) : arrondi à 10 m
// sous 1 km, deux décimales au-delà. Une valeur nulle signifie « position
// inconnue » : on ne renvoie alors aucun nombre, pour ne pas publier une
// distance calculée depuis un point par défaut.
function formatDistance(metres) {
    if (metres === null || metres === undefined || isNaN(metres)) return '';
    if (metres < 1000) {
        if (metres < 10) return Math.round(metres) + ' m';
        return (Math.round(metres / 10) * 10) + ' m';
    }
    return (Math.round(metres / 10) / 100).toFixed(2).replace('.', ',') + ' km';
}

// Formater les dates
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Fonction de debounce
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Gestion des erreurs
function showError(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger alert-dismissible fade show';
    alertDiv.innerHTML = `
        <i class="fas fa-exclamation-circle me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.querySelector('main .container:first-child')?.prepend(alertDiv);
}

function showSuccess(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show';
    alertDiv.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.querySelector('main .container:first-child')?.prepend(alertDiv);
}

// ===== Gestion des sessions =====
// Déconnexion automatique après inactivité
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        if (confirm('Votre session va expirer. Voulez-vous continuer ?')) {
            // Rafraîchir la session
            fetch(window.BASE_URL + 'api/refresh_session.php');
            resetInactivityTimer();
        } else {
            window.location.href = window.BASE_URL + 'deconnexion.php';
        }
    }, 30 * 60 * 1000); // 30 minutes
}

// Réinitialiser le timer à chaque activité
['click', 'keydown', 'scroll', 'mousemove'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer);
});

resetInactivityTimer();