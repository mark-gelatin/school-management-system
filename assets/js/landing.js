function showSide() {
    const sideBar = document.getElementById("mobile-nav");
    if (sideBar) {
        sideBar.style.display = "block";
    } else {
        console.error("Element with ID 'mobile-nav' not found.");
    }
}

function closeSide() {
    const sideBar = document.getElementById("mobile-nav");
    if (sideBar) {
        sideBar.style.display = "none";
    } else {
        console.error("Element with ID 'mobile-nav' not found.");
    }
}

function hideAllDropdowns() {
  var dropdowns = document.getElementsByClassName("list-container");
  for (var i = 0; i < dropdowns.length; i++) {
    dropdowns[i].classList.remove("show");
  }
}

function dropAbout() {
  hideAllDropdowns();
  document.getElementById("about-drop").classList.toggle("show");
}

function dropAdmission() {
  hideAllDropdowns();
  document.getElementById("admission-drop").classList.toggle("show");
}

function dropServices() {
  hideAllDropdowns();
  document.getElementById("services-drop").classList.toggle("show");
}

function dropPortal() {
  hideAllDropdowns();
  document.getElementById("portal-drop").classList.toggle("show");
}

// Close the dropdown if the user clicks outside of it
window.onclick = function(event) {
  if (!event.target.matches('.drop-button')) {
    hideAllDropdowns();
  }
}

// Intersection Observer for animating sections when entering viewport
function animateOnView(selector, animateClass) {
    const section = document.querySelector(selector);
    if (!section) return;
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                section.classList.add(animateClass);
            } else {
                section.classList.remove(animateClass);
            }
        });
    }, { threshold: 0.4 });
    observer.observe(section);
}

// Motto section: animate .motto-title when in view
document.addEventListener("DOMContentLoaded", function() {
    const mottoTitles = document.querySelectorAll("#motto-section .motto-title");
    const mottoSection = document.getElementById("motto-section");
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            mottoTitles.forEach((el, i) => {
                if(entry.isIntersecting) {
                    el.classList.add('animate');
                    el.style.transitionDelay = (i * 0.2) + "s";
                } else {
                    el.classList.remove('animate');
                    el.style.transitionDelay = "0s";
                }
            });
        });
    }, { threshold: 0.4 });
    if(mottoSection) observer.observe(mottoSection);
});

// Why Choose section: animate heading, text, and button
document.addEventListener("DOMContentLoaded", function() {
    animateOnView('#why-choose', 'animate');
});

// Slideshow functionality
let currentSlideIndex = 0;
const slides = document.querySelectorAll('.slide');
const indicators = document.querySelectorAll('.indicator');
let slideInterval;

function showSlide(index) {
    slides.forEach(slide => slide.classList.remove('active'));
    indicators.forEach(indicator => indicator.classList.remove('active'));
    if (slides[index]) slides[index].classList.add('active');
    if (indicators[index]) indicators[index].classList.add('active');
    currentSlideIndex = index;
}

function nextSlide() {
    const nextIndex = (currentSlideIndex + 1) % slides.length;
    showSlide(nextIndex);
}

function currentSlide(index) {
    showSlide(index - 1); // Convert to 0-based index
    resetSlideInterval(); // Reset the auto-slide timer
}

function startSlideInterval() {
    slideInterval = setInterval(nextSlide, 5000); // Change slide every 5 seconds
}

function resetSlideInterval() {
    clearInterval(slideInterval);
    startSlideInterval();
}

// Initialize slideshow when DOM is loaded
document.addEventListener("DOMContentLoaded", function() {
    if (slides.length > 0) {
        showSlide(0);
        startSlideInterval();
        const slideshowContainer = document.querySelector('.slideshow-container');
        if (slideshowContainer) {
            slideshowContainer.addEventListener('mouseenter', () => clearInterval(slideInterval));
            slideshowContainer.addEventListener('mouseleave', () => startSlideInterval());
        }
    }
});

/* NEW: Dynamically reserve space for the absolute nav row so the contact banner
   sits immediately below the navigation with no overlap/gap across all sizes. */
function adjustHeaderPadding() {
    const header = document.querySelector('.header');
    const nav = document.querySelector('.nav-container');
    if (!header || !nav) return;
    // Use actual computed nav height
    const navHeight = nav.offsetHeight || 0;
    header.style.paddingBottom = navHeight + 'px';
}

// Run once loaded and on resize
window.addEventListener('load', adjustHeaderPadding);
window.addEventListener('resize', adjustHeaderPadding);