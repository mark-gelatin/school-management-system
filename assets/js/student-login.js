/* Scroll to login form when error occurs */
document.addEventListener('DOMContentLoaded', function() {
  // Check if error message exists and scroll to login box
  var errorMessage = document.querySelector('.error-message');
  var loginBox = document.querySelector('.login-box');
  var loginBtn = document.querySelector('.login-btn');
  
  if (errorMessage && loginBox) {
    // Ensure page can scroll
    document.body.style.overflowY = 'auto';
    document.documentElement.style.overflowY = 'auto';
    
    // Make login box scrollable
    loginBox.style.maxHeight = '90vh';
    loginBox.style.overflowY = 'auto';
    
    // Scroll to login button to ensure it's visible
    setTimeout(function() {
      if (loginBtn) {
        // First scroll the page to show the login box
        loginBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Then scroll within the login box to show the button
        setTimeout(function() {
          loginBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 400);
      } else {
        loginBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 100);
    
    // Ensure scrolling works on window scroll
    window.addEventListener('scroll', function() {
      document.body.style.overflowY = 'auto';
      document.documentElement.style.overflowY = 'auto';
    }, { once: true });
  }
});

/* Password eye toggle with smooth transitions */
document.addEventListener('DOMContentLoaded', function() {
  var eye = document.getElementById('togglePassword');
  var passwordInput = document.getElementById('passwordInput');
  if (eye && passwordInput) {
    function updateIcon() {
      if (passwordInput.type === 'password') {
        eye.innerHTML = '<i class="fas fa-eye" style="font-size: 18px; color: #666;"></i>';
        eye.title = 'Show Password';
      } else {
        eye.innerHTML = '<i class="fas fa-eye-slash" style="font-size: 18px; color: #666;"></i>';
        eye.title = 'Hide Password';
      }
    }
    
    // Initialize icon
    updateIcon();
    eye.style.display = 'flex';
    eye.style.opacity = '0.5';
    eye.style.transition = 'opacity 0.2s ease, transform 0.15s ease';
    // Ensure transform is always set correctly
    eye.style.transform = 'translateY(-50%)';
    
    // Show/hide eye based on input
    passwordInput.addEventListener('input', function () {
      if (passwordInput.value.length > 0) {
        eye.style.opacity = '1';
        eye.style.display = 'flex';
        updateIcon();
      } else {
        eye.style.opacity = '0.3';
        passwordInput.type = 'password'; // Reset to password type for security
        updateIcon();
      }
    });
    
    // Focus event - show eye when focused
    passwordInput.addEventListener('focus', function() {
      if (passwordInput.value.length > 0) {
        eye.style.opacity = '1';
      } else {
        eye.style.opacity = '0.5';
      }
      eye.style.display = 'flex';
    });
    
    // Blur event - keep eye visible if there's text
    passwordInput.addEventListener('blur', function() {
      if (passwordInput.value.length > 0) {
        eye.style.opacity = '0.7';
      } else {
        eye.style.opacity = '0.3';
      }
    });
    
    // Helper function to set transform while preserving translateY(-50%)
    function setEyeTransform(scale) {
      eye.style.transform = 'translateY(-50%) scale(' + scale + ')';
    }
    
    // Toggle password visibility on click
    eye.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (passwordInput.value.length > 0) {
        passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
        updateIcon();
        // Add a subtle animation feedback while always preserving translateY(-50%)
        setEyeTransform(0.9);
        setTimeout(function() {
          setEyeTransform(1);
        }, 150);
      }
    });
    
    // Override hover to use our helper function
    eye.addEventListener('mouseenter', function() {
      setEyeTransform(1.1);
    });
    
    eye.addEventListener('mouseleave', function() {
      setEyeTransform(1);
    });
    
    // Also handle focus on password field to show eye
    if (passwordInput.value.length > 0) {
      eye.style.opacity = '1';
    }
  }
  
/* Reset password link and content switching functionality */
var resetPasswordLink = document.getElementById('resetPasswordLink');
var loginContent = document.querySelector('.login-content');
var resetContent = document.getElementById('resetContent');

  if (loginContent && resetContent) {
    // Initial state
    loginContent.style.display = '';
    loginContent.style.opacity = '1';
    resetContent.style.display = 'none';
    resetContent.style.opacity = '0';
    
    // Add CSS transitions
    loginContent.style.transition = 'opacity 0.3s ease-in-out';
    resetContent.style.transition = 'opacity 0.3s ease-in-out';
    
    // Function to switch to reset password
    function switchToReset(e) {
      if (e) e.preventDefault();
      
      // Hide login content, show reset content
      loginContent.style.opacity = '0';
      setTimeout(function() {
        loginContent.style.display = 'none';
        resetContent.style.display = '';
        setTimeout(function() {
          resetContent.style.opacity = '1';
        }, 10);
      }, 200);
    }
    
    // Function to switch back to login
    function switchToLogin() {
      // Hide reset content, show login content
      resetContent.style.opacity = '0';
      setTimeout(function() {
        resetContent.style.display = 'none';
        loginContent.style.display = '';
        setTimeout(function() {
          loginContent.style.opacity = '1';
        }, 10);
      }, 200);
    }
    
    // Reset password link click event
    if (resetPasswordLink) {
      resetPasswordLink.addEventListener('click', switchToReset);
    }
    
    // Back to login link in reset content
    var backToLoginLink = document.getElementById('backToLoginLink');
    if (backToLoginLink) {
      backToLoginLink.addEventListener('click', function(e) {
        e.preventDefault();
        switchToLogin();
      });
    }
  }
});
          
/* Reset form validation and redirection */
const resetForm = document.getElementById('resetForm');
if (resetForm) {
  resetForm.addEventListener('submit', function(e) {
    const studentNumberInput = this.querySelector('input[name="student_number"]');
    
    if (!studentNumberInput.value.trim()) {
      e.preventDefault();
      studentNumberInput.setCustomValidity('Please enter your student number.');
      studentNumberInput.reportValidity();
      // Add shake animation
      studentNumberInput.style.animation = 'shake 0.4s';
      setTimeout(() => studentNumberInput.style.animation = '', 400);
    }
  });
}

/* Login form validation with visual feedback */
const loginForm = document.getElementById('loginForm');
if (loginForm) {
  loginForm.addEventListener('submit', function(e) {
    const studentNumberInput = document.getElementById('studentNumber');
    const passwordInput = document.getElementById('passwordInput');
    const loginBtn = this.querySelector('.login-btn');

    studentNumberInput.setCustomValidity('');
    passwordInput.setCustomValidity('');

    let valid = true;

    if (!studentNumberInput.value.trim()) {
      e.preventDefault();
      studentNumberInput.setCustomValidity('Please enter your student number.');
      studentNumberInput.reportValidity();
      // Add shake animation
      studentNumberInput.style.animation = 'shake 0.4s';
      setTimeout(() => studentNumberInput.style.animation = '', 400);
      valid = false;
    }

    if (!passwordInput.value.trim()) {
      e.preventDefault();
      passwordInput.setCustomValidity('Please enter your password.');
      if (valid) passwordInput.reportValidity();
      // Add shake animation
      passwordInput.style.animation = 'shake 0.4s';
      setTimeout(() => passwordInput.style.animation = '', 400);
      valid = false;
    }

    // If valid, add loading state to button
    if (valid && loginBtn) {
      loginBtn.style.opacity = '0.8';
      loginBtn.style.cursor = 'wait';
      loginBtn.disabled = true;
    }
  });
}

['studentNumber', 'passwordInput'].forEach(id => {
  const element = document.getElementById(id);
  if (element) {
    element.addEventListener('input', function() {
      this.setCustomValidity('');
      // Remove shake animation if present
      if (this.style.animation) {
        this.style.animation = '';
      }
    });
  }
});
