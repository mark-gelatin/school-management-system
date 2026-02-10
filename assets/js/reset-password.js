
// // Simple form interactivity
// document.getElementById("verifyForm").addEventListener("submit", function(e) {
//     e.preventDefault();
//     var email = document.getElementById("email").value.trim();
//     var birthdate = document.getElementById("birthdate").value.trim();
//     var msg = document.getElementById("formMessage");

//     // Basic validation
//     if(email === "" || birthdate === "") {
//     msg.textContent = "Please fill in all fields.";
//     msg.style.display = "block";
//     msg.style.color = "#a11c27";
//     return;
//     }

//     // Simulate verification (replace with AJAX in real app)
//     if(email.match(/^tmc\.s.*du\.ph$/i) && birthdate.length === 10) {
//     msg.textContent = "Verification successful! Please check your email for password reset instructions.";
//     msg.style.display = "block";
//     msg.style.color = "green";

//     setTimeout(function() {
//         window.location.href = "reset-success.html";
//     }, 2000);
//     } else {
//     msg.textContent = "Verification failed. Please check your email address and birthdate.";
//     msg.style.display = "flex";
//     msg.style.color = "#a11c27";
//     }
// });

// Password visibility toggle and strength checks
// Eye toggle DRY function for any password input + eye
function setupPasswordEye(passwordInputId, eyeId) {
  var eye = document.getElementById(eyeId);
  var passwordInput = document.getElementById(passwordInputId);
  if (!eye || !passwordInput) return;

  function updateIcon() {
    if (passwordInput.type === 'password') {
      eye.innerHTML = '<img src="assets/view.png" alt="Show password" style="width:20px;height:20px;">';
    } else {
      eye.innerHTML = '<img src="assets/hide.png" alt="Hide password" style="width:20px;height:20px;">';
    }
  }

  eye.style.display = 'none';
  passwordInput.addEventListener('input', function () {
    if (passwordInput.value.length > 0) {
      eye.style.display = '';
      updateIcon();
    } else {
      eye.style.display = 'none';
      passwordInput.type = 'password'; // Reset to password type for security
    }
  });
  eye.addEventListener('click', function () {
    if (passwordInput.value.length > 0) {
      passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
      updateIcon();
    }
  });
}
document.addEventListener('DOMContentLoaded', function() {
  setupPasswordEye('newPassword', 'toggleNewPassword');
  setupPasswordEye('confirmPassword', 'toggleConfirmPassword');
});

// Password strength checks (same as before)
function passwordStrengthChecks(pw) {
  return {
    length: pw.length >= 8,
    uppercase: /[A-Z]/.test(pw),
    lowercase: /[a-z]/.test(pw),
    number: /[0-9]/.test(pw),
    special: /[!@#$%^&*]/.test(pw)
  };
}

// Password strength meter logic
function getPasswordStrength(pw) {
  let score = 0;
  const checks = passwordStrengthChecks(pw);
  if (checks.length) score++;
  if (checks.uppercase) score++;
  if (checks.lowercase) score++;
  if (checks.number) score++;
  if (checks.special) score++;
  // score: 0-2 = weak, 3-4 = medium, 5 = strong
  if (score <= 2) return {level: 'weak', label: 'Weak '};
  if (score <= 4) return {level: 'medium', label: 'Medium'};
  return {level: 'strong', label: 'Strong Password'};
}

// DOM Elements
const newPasswordInput = document.getElementById('newPassword');
const passwordStrengthBar = document.getElementById('passwordStrengthBar');
const passwordStrengthBarInner = passwordStrengthBar ? passwordStrengthBar.querySelector('.password-strength-bar') : null;
const passwordStrengthLabel = passwordStrengthBar ? passwordStrengthBar.querySelector('.password-strength-label') : null;

if (newPasswordInput && passwordStrengthBar && passwordStrengthBarInner && passwordStrengthLabel) {
  newPasswordInput.addEventListener('input', function () {
    const pw = newPasswordInput.value;
    if (pw.length === 0) {
      passwordStrengthBar.style.display = 'none';
      passwordStrengthBarInner.className = 'password-strength-bar';
      passwordStrengthLabel.textContent = '';
      return;
    }
    passwordStrengthBar.style.display = 'flex';
    const result = getPasswordStrength(pw);
    passwordStrengthBarInner.className = 'password-strength-bar ' + result.level;
    passwordStrengthLabel.textContent = result.label;
  });
}

// ...rest of your code (form submission, checklist, etc)...


// Simulate account verification for demo purposes
function simulateVerification(email, birthdate) {
    // Replace with real verification logic
    // Accept any email ending with "edu.ph" and any birthdate for demo
    return email.endsWith("edu.ph") && birthdate;
}

// Password strength checks
function passwordStrengthChecks(pw) {
    return {
        length: pw.length >= 8,
        uppercase: /[A-Z]/.test(pw),
        lowercase: /[a-z]/.test(pw),
        number: /[0-9]/.test(pw),
        special: /[!@#$%^&*]/.test(pw)
    };
}

// DOM Elements
const verifyForm = document.getElementById('verifyForm');
const verifyStep = document.getElementById('verifyStep');
const resetStep = document.getElementById('resetStep');
const formMessage = document.getElementById('formMessage');
const resetForm = document.getElementById('resetForm');
const resetMessage = document.getElementById('resetMessage');
const step1 = document.getElementById('step1');
const step2 = document.getElementById('step2');
const passwordChecklist = document.getElementById('passwordChecklist');
// const newPasswordInput = document.getElementById('newPassword'); // REMOVE this duplicate declaration
const confirmPasswordInput = document.getElementById('confirmPassword');

// Initial state: highlight step 1
step1.classList.add('active');
step2.classList.remove('active');

// Show password requirements by default on page load
passwordChecklist.style.display = 'block';

// Handle Verification Form
verifyForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const email = verifyForm.email.value.trim();
    const birthdate = verifyForm.birthdate.value;
    if (simulateVerification(email, birthdate)) {
        // Next step: show reset form
        verifyStep.style.display = 'none';
        resetStep.style.display = '';
        step1.classList.remove('active');
        step2.classList.add('active');
        formMessage.style.display = 'none';
        // Focus new password field
        setTimeout(() => newPasswordInput.focus(), 100);
    } else {
        formMessage.textContent = "Account not found. Please check your details and try again.";
        formMessage.style.display = 'block';
    }
});

// Password strength checklist appear ONLY on focus, hide on blur if nothing entered
newPasswordInput.addEventListener('focus', function() {
    passwordChecklist.style.display = 'block';
    updatePasswordChecklist();
});
newPasswordInput.addEventListener('input', function() {
    passwordChecklist.style.display = this.value.length > 0 ? 'block' : 'none';
    updatePasswordChecklist();
});
newPasswordInput.addEventListener('blur', function() {
    if (this.value.length === 0) passwordChecklist.style.display = 'none';
});
function updatePasswordChecklist() {
    const pw = newPasswordInput.value;
    const checks = passwordStrengthChecks(pw);
    document.getElementById('check-length').classList.toggle('valid', checks.length);
    document.getElementById('check-uppercase').classList.toggle('valid', checks.uppercase);
    document.getElementById('check-lowercase').classList.toggle('valid', checks.lowercase);
    document.getElementById('check-number').classList.toggle('valid', checks.number);
    document.getElementById('check-special').classList.toggle('valid', checks.special);
}

// Handle Reset Password Form
resetForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const pw = newPasswordInput.value;
    const confirmPw = confirmPasswordInput.value;
    const checks = passwordStrengthChecks(pw);
    if (!checks.length || !checks.uppercase || !checks.lowercase || !checks.number || !checks.special) {
        resetMessage.textContent = "Password does not meet all requirements.";
        resetMessage.style.display = 'block';
        resetMessage.style.color = "#a11c27";
        return;
    }
    if (pw !== confirmPw) {
        resetMessage.textContent = "Passwords do not match.";
        resetMessage.style.display = 'block';
        resetMessage.style.color = "#a11c27";
        return;
    }
    // Success! Replace with actual password reset logic.
    resetMessage.textContent = "Your password has been reset successfully.";
    resetMessage.style.display = 'block';
    resetMessage.style.color = "#22a16d";
    resetForm.querySelector('.submit-btn').disabled = true;
    setTimeout(() => {
        resetForm.querySelector('.submit-btn').disabled = false;
        // Redirect or show login link
    }, 3000);
});