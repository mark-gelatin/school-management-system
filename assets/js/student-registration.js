// === STUDENT REGISTRATION FORM FUNCTIONALITY ===

// Form step navigation
let currentStep = 1;
const totalSteps = 3;

// Initialize form functionality when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeFormSteps();
    initializePasswordFeatures();
    initializeDataPrivacyAct();
    initializeMiddleNameCheckbox();
    initializePhoneDropdowns();
    initializeCustomDropdowns();
    initializeInputValidation();
    initializeEmptyFieldStyling();
    initializeConfirmationCheckbox();
    restoreFormDataFromSession();
    initializeHomeButtonClear();
});

// Form step navigation functionality
function initializeFormSteps() {
    const nextBtn = document.getElementById('nextBtn');
    const nextToStep3 = document.getElementById('nextToStep3');
	const form = document.getElementById('formBox');
    
    // Add validation on Next button click
    if (nextBtn) {
        nextBtn.addEventListener('click', function(e) {
            e.preventDefault();
            validateStep1();
        });
    }
    
    // Add validation for step 2 to step 3
    if (nextToStep3) {
        nextToStep3.addEventListener('click', function(e) {
            e.preventDefault();
            validateStep2();
        });
    }

	// Guard against native form submission; route to the correct validator
	if (form) {
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			if (currentStep === 1) {
				validateStep1();
			} else if (currentStep === 2) {
				validateStep2();
			} else if (currentStep === 3) {
				validateStep3();
			}
		});
	}
    
    // Add validation for step 3 to step 4 (confirmation)
    const submitRegistration = document.getElementById('submitRegistration');
    if (submitRegistration) {
        submitRegistration.addEventListener('click', function(e) {
            e.preventDefault();
            validateStep3();
        });
    }
    
    const backToStep1 = document.getElementById('backToStep1');
    const backToStep2 = document.getElementById('backToStep2');
    const backToStep3 = document.getElementById('backToStep3');
    const backToStep3Btn = document.getElementById('backToStep3Btn');

    // Back to Step 1
    if (backToStep1) {
        backToStep1.addEventListener('click', function(e) {
            e.preventDefault();
            showStep(1);
        });
    }

    // Back to Step 2
    if (backToStep2) {
        backToStep2.addEventListener('click', function(e) {
            e.preventDefault();
            showStep(2);
        });
    }

    // Back to Step 3
    if (backToStep3) {
        backToStep3.addEventListener('click', function(e) {
            e.preventDefault();
            showStep(3);
        });
    }
    
    if (backToStep3Btn) {
        backToStep3Btn.addEventListener('click', function(e) {
            e.preventDefault();
            showStep(3);
        });
    }

    // Final submit button
    const submitFinalBtn = document.getElementById('submitFinalBtn');
    if (submitFinalBtn) {
        submitFinalBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (validateStep4()) {
                // Show success message instead of submitting form
                showSubmissionSuccess();
            }
        });
    }
}

// Show specific step
function showStep(step) {
    // Hide all steps
    for (let i = 1; i <= totalSteps; i++) {
        const stepForm = document.getElementById(`step${i}Form`);
        const stepIndicator = document.getElementById(`step${i}`);
        
        if (stepForm) {
            stepForm.style.display = i === step ? 'block' : 'none';
        }
        
        if (stepIndicator) {
            stepIndicator.classList.toggle('active', i === step);
        }
    }
    
    currentStep = step;
}

// === PASSWORD FUNCTIONALITY ===

// Password strength and validation features
function initializePasswordFeatures() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const togglePassword = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const confirmPasswordSection = document.getElementById('confirmPasswordSection');

    if (passwordInput) {
        // Password strength indicator
        passwordInput.addEventListener('input', function() {
            const strengthContainer = document.getElementById('passwordStrengthContainer');
            
            // Show strength container when user starts typing
            if (this.value.length > 0 && strengthContainer) {
                strengthContainer.classList.add('show');
            } else if (strengthContainer) {
                strengthContainer.classList.remove('show');
            }
            
            updatePasswordStrength();
            updatePasswordChecklist();
            checkPasswordRequirements();
        });

        // Password toggle visibility
        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                togglePasswordVisibility(passwordInput, this);
            });
        }
    }

    if (confirmPasswordInput && toggleConfirmPassword) {
        toggleConfirmPassword.addEventListener('click', function() {
            togglePasswordVisibility(confirmPasswordInput, this);
        });
    }
}

// Toggle password visibility
function togglePasswordVisibility(input, toggleBtn) {
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    
    const icon = toggleBtn.querySelector('img');
    if (icon) {
        icon.src = isPassword ? 'assets/hide.png' : 'assets/view.png';
        icon.alt = isPassword ? 'Hide password' : 'Show password';
    }
}

// Update password strength indicator
function updatePasswordStrength() {
    const password = document.getElementById('password').value;
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthLabel = document.getElementById('passwordStrengthLabel');
    
    if (!strengthBar || !strengthLabel) return;

    const strength = calculatePasswordStrength(password);
    
    // Update strength bar
    strengthBar.style.width = strength.percentage + '%';
    strengthBar.className = 'password-strength-bar ' + strength.class;
    
    // Update strength label
    strengthLabel.textContent = strength.label;
    strengthLabel.className = 'password-strength-label ' + strength.class;
}

// Calculate password strength
function calculatePasswordStrength(password) {
    let score = 0;
    let feedback = [];

    // Length check
    if (password.length >= 8) {
        score += 20;
    } else {
        feedback.push('At least 8 characters');
    }

    // Uppercase check
    if (/[A-Z]/.test(password)) {
        score += 20;
    } else {
        feedback.push('One uppercase letter');
    }

    // Lowercase check
    if (/[a-z]/.test(password)) {
        score += 20;
    } else {
        feedback.push('One lowercase letter');
    }

    // Number check
    if (/[0-9]/.test(password)) {
        score += 20;
    } else {
        feedback.push('One number');
    }

    // Special character check
    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
        score += 20;
    } else {
        feedback.push('One special character');
    }

    // Determine strength level
    if (score < 40) {
        return { percentage: score, class: 'weak', label: 'Weak Password' };
    } else if (score < 80) {
        return { percentage: score, class: 'medium', label: 'Medium Password' };
    } else {
        return { percentage: score, class: 'strong', label: 'Strong Password' };
    }
}

// Update password checklist
function updatePasswordChecklist() {
    const password = document.getElementById('password').value;
    
    const checks = {
        'check-length': password.length >= 8,
        'check-uppercase': /[A-Z]/.test(password),
        'check-lowercase': /[a-z]/.test(password),
        'check-number': /[0-9]/.test(password),
        'check-special': /[!@#$%^&*(),.?":{}|<>]/.test(password)
    };

    Object.entries(checks).forEach(([checkId, isValid]) => {
        const checkItem = document.getElementById(checkId);
        if (checkItem) {
            const checkIcon = checkItem.querySelector('.check-icon');
            if (checkIcon) {
                checkIcon.textContent = isValid ? '✓' : '✗';
                checkIcon.style.color = isValid ? '#28a745' : '#dc3545';
            }
        }
    });
}

// Check if all password requirements are met and show/hide confirm password field
function checkPasswordRequirements() {
    const password = document.getElementById('password').value;
    const confirmPasswordSection = document.getElementById('confirmPasswordSection');
    
    if (!confirmPasswordSection) return;
    
    // Check if all requirements are met
    const allRequirementsMet = (
        password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[a-z]/.test(password) &&
        /[0-9]/.test(password) &&
        /[!@#$%^&*(),.?":{}|<>]/.test(password)
    );
    
    if (allRequirementsMet) {
        // Show confirm password field with smooth animation
        setTimeout(() => {
            confirmPasswordSection.classList.add('show');
        }, 100); // Small delay for smoother animation
        console.log('All password requirements met - showing confirm password field');
    } else {
        // Hide confirm password field
        confirmPasswordSection.classList.remove('show');
        // Clear confirm password field when hiding
        const confirmPasswordInput = document.getElementById('confirmPassword');
        if (confirmPasswordInput) {
            confirmPasswordInput.value = '';
        }
        console.log('Password requirements not met - hiding confirm password field');
    }
}

// === DATA PRIVACY ACT (10173) FUNCTIONALITY ===

// Data Privacy Act checkbox and proceed button functionality
function initializeDataPrivacyAct() {
    console.log('Initializing Data Privacy Act functionality...');
    
    // Add a small delay to ensure DOM is fully loaded
    setTimeout(() => {
    const agreeCheckbox = document.getElementById('agreeCheckbox');
    const proceedBtn = document.getElementById('proceedBtn');
        
        console.log('Agree checkbox found:', !!agreeCheckbox);
        console.log('Proceed button found:', !!proceedBtn);
        
        if (agreeCheckbox) {
            console.log('Checkbox element:', agreeCheckbox);
            console.log('Checkbox checked state:', agreeCheckbox.checked);
        }
        
        if (proceedBtn) {
            console.log('Button element:', proceedBtn);
            console.log('Button disabled state:', proceedBtn.disabled);
        }
    
    if (agreeCheckbox && proceedBtn) {
            console.log('Setting up Data Privacy Act event listeners...');
        // Enable/disable proceed button based on checkbox
        agreeCheckbox.addEventListener('change', function() {
                console.log('Checkbox changed. Checked:', this.checked);
            proceedBtn.disabled = !this.checked;
            if (this.checked) {
                    proceedBtn.disabled = false;
                    proceedBtn.style.setProperty('opacity', '1', 'important');
                    proceedBtn.style.setProperty('cursor', 'pointer', 'important');
                    proceedBtn.style.setProperty('background', 'linear-gradient(135deg, #a11c27 0%, #b31310 100%)', 'important');
                    proceedBtn.style.setProperty('color', 'white', 'important');
                    console.log('Button enabled');
            } else {
                    proceedBtn.disabled = true;
                    proceedBtn.style.setProperty('opacity', '0.6', 'important');
                    proceedBtn.style.setProperty('cursor', 'not-allowed', 'important');
                    proceedBtn.style.setProperty('background', '#e9ecef', 'important');
                    proceedBtn.style.setProperty('color', '#6c757d', 'important');
                    console.log('Button disabled');
                }
            });
            
            // Add click event listener to proceed button
            proceedBtn.addEventListener('click', function(e) {
                e.preventDefault(); // Prevent any default behavior
                console.log('Proceed button clicked. Checkbox checked:', agreeCheckbox.checked);
            if (agreeCheckbox.checked) {
                    console.log('Proceeding to registration...');
                    
                    // Check if we're running on localhost or file protocol
                    const currentProtocol = window.location.protocol;
                    const currentHostname = window.location.hostname;
                    
                    console.log('Current protocol:', currentProtocol);
                    console.log('Current hostname:', currentHostname);
                    
                    // Determine the correct URL based on the current environment
                    let targetUrl = 'student-registration-portal.php';
                    
                    if (currentProtocol === 'file:') {
                        // If running from file://, we need to use a web server
                        alert('Please run this application through a web server (like XAMPP) instead of opening the HTML file directly. The PHP files need to be processed by a server.');
                        return;
                    } else if (currentHostname === 'localhost' || currentHostname === '127.0.0.1') {
                        // Running on localhost - use relative URL
                        targetUrl = 'student-registration-portal.php';
            } else {
                        // Running on a different server - use relative URL
                        targetUrl = 'student-registration-portal.php';
                    }
                    
                    console.log('Target URL:', targetUrl);
                    
                    // Try redirect with error handling
                    try {
                        console.log('Attempting redirect to:', targetUrl);
                        window.location.href = targetUrl;
                    } catch (error) {
                        console.error('Redirect failed:', error);
                        alert('Unable to redirect to the registration page. Please check that the web server is running and the file exists.');
                    }
                } else {
                    console.log('Checkbox not checked, showing alert');
                alert('Please agree to the Data Privacy Act (R.A. 10173) before proceeding.');
                return false;
                }
            });
        } else {
            console.log('Data Privacy Act elements not found - this might not be the data privacy page');
        }
        
        // Check if checkbox is already checked on page load
        if (agreeCheckbox && proceedBtn && agreeCheckbox.checked) {
            console.log('Checkbox is already checked on page load - enabling button');
            proceedBtn.disabled = false;
            proceedBtn.style.setProperty('opacity', '1', 'important');
            proceedBtn.style.setProperty('cursor', 'pointer', 'important');
            proceedBtn.style.setProperty('background', 'linear-gradient(135deg, #a11c27 0%, #b31310 100%)', 'important');
            proceedBtn.style.setProperty('color', 'white', 'important');
        }
    }, 100); // 100ms delay to ensure DOM is ready
    
    // Add global test function for debugging
    window.testConsentButton = function() {
        const agreeCheckbox = document.getElementById('agreeCheckbox');
        const proceedBtn = document.getElementById('proceedBtn');
        
        console.log('Manual test - Checkbox found:', !!agreeCheckbox);
        console.log('Manual test - Button found:', !!proceedBtn);
        
        if (agreeCheckbox && proceedBtn) {
            console.log('Manual test - Checkbox checked:', agreeCheckbox.checked);
            console.log('Manual test - Button disabled:', proceedBtn.disabled);
            
            if (agreeCheckbox.checked) {
                proceedBtn.disabled = false;
                proceedBtn.style.setProperty('opacity', '1', 'important');
                proceedBtn.style.setProperty('cursor', 'pointer', 'important');
                proceedBtn.style.setProperty('background', 'linear-gradient(135deg, #a11c27 0%, #b31310 100%)', 'important');
                proceedBtn.style.setProperty('color', 'white', 'important');
                console.log('Manual test - Button enabled');
            }
        }
    };
    
    // Add global test function for redirect debugging
    window.testRedirect = function() {
        console.log('Testing redirect methods...');
        console.log('Current URL:', window.location.href);
        console.log('Current pathname:', window.location.pathname);
        console.log('Current hostname:', window.location.hostname);
        
        // Test different redirect methods
        console.log('Testing window.location.href...');
        try {
            window.location.href = 'student-registration-portal.php';
        } catch (error) {
            console.log('window.location.href failed:', error);
        }
    };
}

// === MIDDLE NAME CHECKBOX FUNCTIONALITY ===

function initializeMiddleNameCheckbox() {
    const noMiddleNameCheckbox = document.getElementById('noMiddleName');
    const middleNameInput = document.getElementById('middleName');
    
    if (noMiddleNameCheckbox && middleNameInput) {
        // Function to update middle name field state
        function updateMiddleNameFieldState() {
            console.log('Updating middle name field state - checkbox checked:', noMiddleNameCheckbox.checked);
            
            if (noMiddleNameCheckbox.checked) {
                // If checkbox is checked, clear the field and make it optional
                middleNameInput.value = '';
                middleNameInput.disabled = true;
                middleNameInput.style.backgroundColor = '#f5f5f5';
                middleNameInput.style.color = '#999';
                middleNameInput.required = false;
                // Remove any validation errors
                middleNameInput.classList.remove('validation-error');
                middleNameInput.style.border = '';
                middleNameInput.style.borderBottom = '';
                middleNameInput.style.background = '';
                console.log('Checkbox checked - removed validation error and made field optional');
            } else {
                // If checkbox is unchecked, enable the field and make it required
                middleNameInput.disabled = false;
                middleNameInput.style.backgroundColor = '';
                middleNameInput.style.color = '';
                middleNameInput.required = true;
                console.log('Checkbox unchecked - made field required');
            }
        }
        
        // Update state immediately on page load
        updateMiddleNameFieldState();
        
        // Update state when checkbox changes
        noMiddleNameCheckbox.addEventListener('change', updateMiddleNameFieldState);
        
        // Also add a click event listener as backup
        noMiddleNameCheckbox.addEventListener('click', function() {
            setTimeout(updateMiddleNameFieldState, 10);
        });
    }
}

// === PHONE DROPDOWN FUNCTIONALITY ===

function initializePhoneDropdowns() {
    // Complete phone dropdown data - sorted alphabetically
    const countries = [
        { code: 'AF', name: 'Afghanistan', dial: '+93', flag: 'https://flagcdn.com/24x18/af.png' },
        { code: 'AR', name: 'Argentina', dial: '+54', flag: 'https://flagcdn.com/24x18/ar.png' },
        { code: 'AU', name: 'Australia', dial: '+61', flag: 'https://flagcdn.com/24x18/au.png' },
        { code: 'BD', name: 'Bangladesh', dial: '+880', flag: 'https://flagcdn.com/24x18/bd.png' },
        { code: 'BR', name: 'Brazil', dial: '+55', flag: 'https://flagcdn.com/24x18/br.png' },
        { code: 'CA', name: 'Canada', dial: '+1', flag: 'https://flagcdn.com/24x18/ca.png' },
        { code: 'CN', name: 'China', dial: '+86', flag: 'https://flagcdn.com/24x18/cn.png' },
        { code: 'EG', name: 'Egypt', dial: '+20', flag: 'https://flagcdn.com/24x18/eg.png' },
        { code: 'FR', name: 'France', dial: '+33', flag: 'https://flagcdn.com/24x18/fr.png' },
        { code: 'DE', name: 'Germany', dial: '+49', flag: 'https://flagcdn.com/24x18/de.png' },
        { code: 'GB', name: 'United Kingdom', dial: '+44', flag: 'https://flagcdn.com/24x18/gb.png' },
        { code: 'IN', name: 'India', dial: '+91', flag: 'https://flagcdn.com/24x18/in.png' },
        { code: 'ID', name: 'Indonesia', dial: '+62', flag: 'https://flagcdn.com/24x18/id.png' },
        { code: 'IR', name: 'Iran', dial: '+98', flag: 'https://flagcdn.com/24x18/ir.png' },
        { code: 'IQ', name: 'Iraq', dial: '+964', flag: 'https://flagcdn.com/24x18/iq.png' },
        { code: 'IL', name: 'Israel', dial: '+972', flag: 'https://flagcdn.com/24x18/il.png' },
        { code: 'IT', name: 'Italy', dial: '+39', flag: 'https://flagcdn.com/24x18/it.png' },
        { code: 'JP', name: 'Japan', dial: '+81', flag: 'https://flagcdn.com/24x18/jp.png' },
        { code: 'JO', name: 'Jordan', dial: '+962', flag: 'https://flagcdn.com/24x18/jo.png' },
        { code: 'KE', name: 'Kenya', dial: '+254', flag: 'https://flagcdn.com/24x18/ke.png' },
        { code: 'KR', name: 'South Korea', dial: '+82', flag: 'https://flagcdn.com/24x18/kr.png' },
        { code: 'LB', name: 'Lebanon', dial: '+961', flag: 'https://flagcdn.com/24x18/lb.png' },
        { code: 'MY', name: 'Malaysia', dial: '+60', flag: 'https://flagcdn.com/24x18/my.png' },
        { code: 'MA', name: 'Morocco', dial: '+212', flag: 'https://flagcdn.com/24x18/ma.png' },
        { code: 'MX', name: 'Mexico', dial: '+52', flag: 'https://flagcdn.com/24x18/mx.png' },
        { code: 'NP', name: 'Nepal', dial: '+977', flag: 'https://flagcdn.com/24x18/np.png' },
        { code: 'NG', name: 'Nigeria', dial: '+234', flag: 'https://flagcdn.com/24x18/ng.png' },
        { code: 'PK', name: 'Pakistan', dial: '+92', flag: 'https://flagcdn.com/24x18/pk.png' },
        { code: 'PH', name: 'Philippines', dial: '+63', flag: 'https://flagcdn.com/24x18/ph.png' },
        { code: 'RU', name: 'Russia', dial: '+7', flag: 'https://flagcdn.com/24x18/ru.png' },
        { code: 'SA', name: 'Saudi Arabia', dial: '+966', flag: 'https://flagcdn.com/24x18/sa.png' },
        { code: 'SG', name: 'Singapore', dial: '+65', flag: 'https://flagcdn.com/24x18/sg.png' },
        { code: 'ZA', name: 'South Africa', dial: '+27', flag: 'https://flagcdn.com/24x18/za.png' },
        { code: 'LK', name: 'Sri Lanka', dial: '+94', flag: 'https://flagcdn.com/24x18/lk.png' },
        { code: 'ES', name: 'Spain', dial: '+34', flag: 'https://flagcdn.com/24x18/es.png' },
        { code: 'TH', name: 'Thailand', dial: '+66', flag: 'https://flagcdn.com/24x18/th.png' },
        { code: 'TR', name: 'Turkey', dial: '+90', flag: 'https://flagcdn.com/24x18/tr.png' },
        { code: 'AE', name: 'United Arab Emirates', dial: '+971', flag: 'https://flagcdn.com/24x18/ae.png' },
        { code: 'US', name: 'United States', dial: '+1', flag: 'https://flagcdn.com/24x18/us.png' },
        { code: 'VN', name: 'Vietnam', dial: '+84', flag: 'https://flagcdn.com/24x18/vn.png' }
    ];

    // Initialize phone dropdowns
    initializePhoneDropdown('phoneCountryFlag', 'customPhoneSelect', 'selectedAbbr', 'countryList', 'phoneCountryDial', 'phoneNumber');
    initializePhoneDropdown('motherPhoneCountryFlag', 'motherCustomPhoneSelect', 'motherSelectedAbbr', 'motherCountryList', 'motherPhoneCountryDial', 'motherPhoneNumber');
    initializePhoneDropdown('fatherPhoneCountryFlag', 'fatherCustomPhoneSelect', 'fatherSelectedAbbr', 'fatherCountryList', 'fatherPhoneCountryDial', 'fatherPhoneNumber');
    initializePhoneDropdown('emergencyPhoneCountryFlag', 'emergencyCustomPhoneSelect', 'emergencySelectedAbbr', 'emergencyCountryList', 'emergencyPhoneCountryDial', 'emergencyPhoneNumber');

    function initializePhoneDropdown(flagId, selectId, abbrId, listId, dialId, phoneId) {
        const flag = document.getElementById(flagId);
        const select = document.getElementById(selectId);
        const abbr = document.getElementById(abbrId);
        const list = document.getElementById(listId);
        const dial = document.getElementById(dialId);
        const phone = document.getElementById(phoneId);

        if (!flag || !select || !abbr || !list || !dial || !phone) return;

        // Populate country list
        countries.forEach(country => {
            const li = document.createElement('li');
            li.innerHTML = `
                <img src="${country.flag}" alt="${country.name}" class="country-flag">
                <span class="country-name">${country.name}</span>
                <span class="country-dial">${country.dial}</span>
            `;
            li.addEventListener('click', (e) => {
                e.stopPropagation();
                flag.src = country.flag;
                abbr.textContent = country.code;
                dial.textContent = country.dial;
                select.setAttribute('aria-expanded', 'false');
                list.hidden = true;
                
                // Ensure dropdown closes immediately
                setTimeout(() => {
                    list.hidden = true;
                    select.setAttribute('aria-expanded', 'false');
                }, 10);
            });
            list.appendChild(li);
        });

        // Toggle dropdown
        select.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isExpanded = select.getAttribute('aria-expanded') === 'true';
            select.setAttribute('aria-expanded', !isExpanded);
            list.hidden = isExpanded;
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            select.setAttribute('aria-expanded', 'false');
            list.hidden = true;
        });
    }
}

// === CUSTOM DROPDOWN FUNCTIONALITY ===

function initializeCustomDropdowns() {
    // Complete nationality data - sorted alphabetically
    const nationalities = [
        'Filipino', 'Afghan', 'American', 'Argentinian', 'Australian', 'Bangladeshi', 'Brazilian', 
        'British', 'Canadian', 'Chinese', 'Egyptian', 'Emirati',
        'French', 'German', 'Indian', 'Indonesian', 'Iranian', 'Iraqi', 
        'Israeli', 'Italian', 'Japanese', 'Jordanian', 'Kenyan', 'Korean', 
        'Lebanese', 'Malaysian', 'Mexican', 'Moroccan', 'Nepalese', 'Nigerian', 
        'Other', 'Pakistani', 'Russian', 'Saudi Arabian', 'Singaporean', 'South African', 
        'Spanish', 'Sri Lankan', 'Thai', 'Turkish', 'Vietnamese'
    ];

    // Program data
    const programs = [
        { value: 'BS Criminology', label: 'Bachelor of Science in Criminology' },
        { value: 'BS Hospitality Management', label: 'Bachelor of Science in Hospitality Management' },
        { value: 'BS Computer Science', label: 'Bachelor of Science in Computer Science' }
    ];

    // Educational status data
    const educationalStatuses = [
        { value: 'New Student', label: 'New Student' },
        { value: 'Transferee', label: 'Transferee' }
    ];

    // Suffix data
    const suffixes = [
        { value: 'N/A', label: 'N/A' },
        { value: 'Jr.', label: 'Jr.' },
        { value: 'Sr.', label: 'Sr.' },
        { value: 'I', label: 'I' },
        { value: 'II', label: 'II' },
        { value: 'III', label: 'III' },
        { value: 'IV', label: 'IV' },
        { value: 'V', label: 'V' },
        { value: 'VI', label: 'VI' },
        { value: 'VII', label: 'VII' },
        { value: 'VIII', label: 'VIII' },
        { value: 'IX', label: 'IX' },
        { value: 'X', label: 'X' }
    ];

    // Initialize nationality dropdown
    initializeCustomDropdown('customNationalitySelect', 'selectedNationality', 'nationalityList', 'nationality', nationalities);
    
    // Initialize program dropdown
    initializeCustomDropdown('customProgramSelect', 'selectedProgram', 'programList', 'program', programs);
    
    // Initialize educational status dropdown
    initializeCustomDropdown('customEducationalStatusSelect', 'selectedEducationalStatus', 'educationalStatusList', 'educationalStatus', educationalStatuses);
    
    // Initialize suffix dropdown
    initializeCustomDropdown('customSuffixSelect', 'selectedSuffix', 'suffixList', 'suffix', suffixes);

    function initializeCustomDropdown(selectId, selectedId, listId, hiddenInputId, options) {
        const select = document.getElementById(selectId);
        const selected = document.getElementById(selectedId);
        const list = document.getElementById(listId);
        const hiddenInput = document.getElementById(hiddenInputId);

        if (!select || !selected || !list || !hiddenInput) return;

        // Populate dropdown list
        options.forEach(option => {
            const li = document.createElement('li');
            const value = typeof option === 'string' ? option : option.value;
            const label = typeof option === 'string' ? option : option.label;
            
            li.textContent = label;
            li.dataset.value = value;
            
            li.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Update the display and hidden input
                selected.textContent = label;
                hiddenInput.value = value;
                
                // Remove selected class from all items
                list.querySelectorAll('li').forEach(item => item.classList.remove('selected'));
                // Add selected class to clicked item
                li.classList.add('selected');
                
                // Close dropdown immediately
                select.setAttribute('aria-expanded', 'false');
                list.hidden = true;
                
                // Force close with timeout as backup
                setTimeout(() => {
                    list.hidden = true;
                    select.setAttribute('aria-expanded', 'false');
                }, 50);
            });
            
            list.appendChild(li);
        });

        // Toggle dropdown
        select.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isExpanded = select.getAttribute('aria-expanded') === 'true';
            select.setAttribute('aria-expanded', !isExpanded);
            list.hidden = isExpanded;
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            select.setAttribute('aria-expanded', 'false');
            list.hidden = true;
        });
    }
}

// === INPUT VALIDATION AND AUTO-CAPITALIZATION ===

function initializeInputValidation() {
    // Validate parent names - prevent numbers and allow only letters and common name characters
    const parentNameInputs = document.querySelectorAll('#motherName, #fatherName');
    parentNameInputs.forEach(input => {
        // Prevent invalid characters from being typed
        input.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Allow letters (A-Z, a-z), spaces, apostrophes (39), hyphens (45), and periods (46)
            const allowedKeyCodes = [
                // Letters
                ...Array.from({length: 26}, (_, i) => 65 + i), // A-Z
                ...Array.from({length: 26}, (_, i) => 97 + i), // a-z
                // Special characters
                32,  // Space
                39,  // Apostrophe (')
                45,  // Hyphen (-)
                46   // Period (.)
            ];
            if (!allowedKeyCodes.includes(e.keyCode)) {
                e.preventDefault();
                // Show temporary error styling
                this.classList.add('validation-error');
                setTimeout(() => {
                    this.classList.remove('validation-error');
                }, 1000);
            }
        });
        
        // Remove invalid characters on input (handles pasted content and other edge cases)
        input.addEventListener('input', function(e) {
            const originalValue = this.value;
            // Allow only letters, spaces, apostrophes, hyphens, and periods
            // Remove any characters that are not in the allowed set
            this.value = originalValue.replace(/[^a-zA-Z\s'.-]/g, '');
            
            // If value changed, show a brief warning
            if (originalValue !== this.value) {
                // Show temporary error styling
                this.classList.add('validation-error');
                setTimeout(() => {
                    this.classList.remove('validation-error');
                }, 2000);
            }
        });
        
        // Also validate on paste
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            // Remove invalid characters from pasted text (keep only letters, spaces, apostrophes, hyphens, periods)
            const cleanedText = pastedText.replace(/[^a-zA-Z\s'.-]/g, '');
            // Insert cleaned text at cursor position
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.substring(0, start) + cleanedText + this.value.substring(end);
            this.setSelectionRange(start + cleanedText.length, start + cleanedText.length);
            
            // Show warning if text was cleaned
            if (pastedText !== cleanedText) {
                this.classList.add('validation-error');
                setTimeout(() => {
                    this.classList.remove('validation-error');
                }, 2000);
            }
        });
    });
    
    // Auto-capitalization for text inputs (exclude address fields that need special handling, postal code, and parent name fields)
    const textInputs = document.querySelectorAll('input[type="text"]:not([id="baranggay"]):not([id="address"]):not([id="addressLine2"]):not([id="postalCode"]):not([id="motherName"]):not([id="fatherName"])');
    textInputs.forEach(input => {
        // Auto-capitalize on input
        input.addEventListener('input', function() {
            // Store cursor position
            const cursorPos = this.selectionStart;
            
            // Capitalize the whole text
            this.value = this.value.toUpperCase();
            
            // Restore cursor position
            this.setSelectionRange(cursorPos, cursorPos);
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
        });
        
        // Prevent numbers in text inputs - only allow letters and spaces
        input.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Only allow letters (A-Z, a-z) and spaces - prevent numbers and special characters
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122) || e.keyCode === 32)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-letters (numbers, special characters)
        input.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Only allow letters and spaces
            if (!/^[a-zA-Z\s]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    });
    
    // Note: Parent name validation is handled above in the parentNameInputs section
    // This section is intentionally left empty as validation is already implemented
    
    // Add gender radio button validation clearing
    const genderRadios = document.querySelectorAll('input[name="gender"]');
    console.log('Found', genderRadios.length, 'gender radio buttons');
    genderRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            console.log('Gender radio changed to:', this.value);
            // Remove validation error from all individual gender boxes when any is selected
            const genderBoxes = document.querySelectorAll('.gender-box');
            genderBoxes.forEach(box => {
                box.classList.remove('validation-error');
            });
            console.log('Removed validation-error class from all gender boxes');
        });
    });
    
    // Barangay field - allow alphanumeric input
    const barangayInput = document.getElementById('baranggay');
    if (barangayInput) {
        // Auto-capitalize on input
        barangayInput.addEventListener('input', function() {
            // Store cursor position
            const cursorPos = this.selectionStart;
            
            // Capitalize the whole text
            this.value = this.value.toUpperCase();
            
            // Restore cursor position
            this.setSelectionRange(cursorPos, cursorPos);
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
        });
        
        barangayInput.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Allow letters (A-Z, a-z), numbers (0-9), and spaces
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122) || (e.keyCode >= 48 && e.keyCode <= 57) || e.keyCode === 32)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-alphanumeric characters
        barangayInput.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Allow letters, numbers, and spaces
            if (!/^[a-zA-Z0-9\s]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    }
    
    // Address field (House No./Building/Street Name) - allow alphanumeric input
    const addressInput = document.getElementById('address');
    if (addressInput) {
        // Auto-capitalize on input
        addressInput.addEventListener('input', function() {
            // Store cursor position
            const cursorPos = this.selectionStart;
            
            // Capitalize the whole text
            this.value = this.value.toUpperCase();
            
            // Restore cursor position
            this.setSelectionRange(cursorPos, cursorPos);
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
        });
        
        addressInput.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Allow letters (A-Z, a-z), numbers (0-9), and spaces
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122) || (e.keyCode >= 48 && e.keyCode <= 57) || e.keyCode === 32)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-alphanumeric characters
        addressInput.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Allow letters, numbers, and spaces
            if (!/^[a-zA-Z0-9\s]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    }
    
    // Address Line 2 field - allow alphanumeric input
    const addressLine2Input = document.getElementById('addressLine2');
    if (addressLine2Input) {
        // Auto-capitalize on input
        addressLine2Input.addEventListener('input', function() {
            // Store cursor position
            const cursorPos = this.selectionStart;
            
            // Capitalize the whole text
            this.value = this.value.toUpperCase();
            
            // Restore cursor position
            this.setSelectionRange(cursorPos, cursorPos);
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
        });
        
        addressLine2Input.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Allow letters (A-Z, a-z), numbers (0-9), and spaces
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122) || (e.keyCode >= 48 && e.keyCode <= 57) || e.keyCode === 32)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-alphanumeric characters
        addressLine2Input.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Allow letters, numbers, and spaces
            if (!/^[a-zA-Z0-9\s]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    }
    
    // Postal Code field - only allow numbers
    const postalCodeInput = document.getElementById('postalCode');
    if (postalCodeInput) {
        postalCodeInput.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter
            if ([8, 9, 27, 13, 46].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Only allow numbers (0-9)
            if (!(e.keyCode >= 48 && e.keyCode <= 57)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-numbers
        postalCodeInput.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Only allow numbers
            if (!/^[0-9]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    }
    
    // Emergency Address field - allow alphanumeric input with auto-capitalization
    const emergencyAddressInput = document.getElementById('emergencyAddress');
    if (emergencyAddressInput) {
        // Auto-capitalize on input
        emergencyAddressInput.addEventListener('input', function() {
            // Store cursor position
            const cursorPos = this.selectionStart;
            
            // Capitalize the whole text
            this.value = this.value.toUpperCase();
            
            // Restore cursor position
            this.setSelectionRange(cursorPos, cursorPos);
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
        });
        
        emergencyAddressInput.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter, space
            if ([8, 9, 27, 13, 46, 32].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Allow letters (A-Z, a-z), numbers (0-9), and spaces
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122) || (e.keyCode >= 48 && e.keyCode <= 57) || e.keyCode === 32)) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting non-alphanumeric characters
        emergencyAddressInput.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            // Allow letters, numbers, and spaces
            if (!/^[a-zA-Z0-9\s]*$/.test(paste)) {
                e.preventDefault();
            }
        });
    }
    
    // Phone number validation
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        // Format phone number as user types
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove non-digits
            if (value.length > 0) {
                // Format as XXX-XXX-XXXX for US numbers or similar
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.slice(0, 3) + '-' + value.slice(3);
                } else {
                    value = value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10);
                }
            }
            this.value = value;
            
            // Remove validation error when user types
            this.classList.remove('validation-error');
            const phoneGroup = this.closest('.phone-group');
            if (phoneGroup) {
                phoneGroup.classList.remove('validation-error');
            }
        });
        
        // Prevent letters in phone inputs
        input.addEventListener('keypress', function(e) {
            // Allow backspace, delete, tab, escape, enter
            if ([8, 9, 27, 13, 46].indexOf(e.keyCode) !== -1 ||
                // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                (e.keyCode === 65 && e.ctrlKey === true) ||
                (e.keyCode === 67 && e.ctrlKey === true) ||
                (e.keyCode === 86 && e.ctrlKey === true) ||
                (e.keyCode === 88 && e.ctrlKey === true)) {
                return;
            }
            // Only allow numbers (0-9) and dash (-)
            if ((e.keyCode < 48 || e.keyCode > 57) && (e.keyCode < 96 || e.keyCode > 105) && e.keyCode !== 189) {
                e.preventDefault();
            }
        });
        
        // Prevent pasting letters
        input.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            if (/[a-zA-Z]/.test(paste)) {
                e.preventDefault();
            }
        });
    });
}

// === INITIALIZE EMPTY FIELD STYLING ===

function initializeEmptyFieldStyling() {
    // Remove all validation error classes on page load
    const allInputs = document.querySelectorAll('input, select, .custom-dropdown-group, .phone-group');
    allInputs.forEach(element => {
        element.classList.remove('validation-error', 'empty');
    });
}

// === STEP VALIDATION ===

function validateStep1() {
    console.log('validateStep1 function called');
    let isValid = true;
    
    // Clear previous validation errors
    const allInputs = document.querySelectorAll('input, select, .custom-dropdown-group, .phone-group');
    console.log('Found', allInputs.length, 'input elements to clear validation errors');
    allInputs.forEach(element => {
        element.classList.remove('validation-error');
    });
    
    // Remove any existing validation message
    const existingMessage = document.querySelector('.validation-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Validate fields in step 1 (exclude individual phone inputs and gender for clean design)
    const allFields = document.querySelectorAll('#step1Form input, #step1Form select, #step1Form .custom-dropdown-group, #step1Form .phone-group');
    console.log('Found', allFields.length, 'fields to validate in step 1');
    
    allFields.forEach(field => {
        let isEmpty = false;
        
        // Skip individual phone inputs and gender fields for clean design
        if (field.type === 'tel' || field.name === 'gender') {
            console.log('Skipping field:', field.id || field.name || field.className);
            return;
        }
        
        console.log('Validating field:', field.id || field.name || field.className, 'Value:', field.value);
        
        // Special handling for middle name - check if "no middle name" checkbox is checked
        if (field.id === 'middleName') {
            const noMiddleNameCheckbox = document.getElementById('noMiddleName');
            console.log('Step 1 - Middle name validation - checkbox found:', !!noMiddleNameCheckbox);
            console.log('Step 1 - Middle name validation - checkbox checked:', noMiddleNameCheckbox ? noMiddleNameCheckbox.checked : 'checkbox not found');
            console.log('Step 1 - Middle name validation - field value:', field.value);
            
            if (noMiddleNameCheckbox && noMiddleNameCheckbox.checked) {
                // If checkbox is checked, middle name is not required
                field.classList.remove('validation-error');
                field.style.border = '';
                field.style.borderBottom = '';
                field.style.background = '';
                console.log('Step 1 - Middle name validation - checkbox checked, field is valid (no validation error)');
                return; // Skip validation for this field
            } else {
                // If checkbox is not checked, validate normally
                isEmpty = field.value.trim() === '';
                console.log('Step 1 - Middle name validation - checkbox not checked, field is empty:', isEmpty);
            }
        } else if (field.classList.contains('custom-dropdown-group')) {
            // For custom dropdowns
            const hiddenInput = field.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                // Allow 'N/A' for suffix field, but not for other dropdowns
                if (hiddenInput.id === 'suffix') {
                    isEmpty = hiddenInput.value === ''; // Only check for empty string, 'N/A' is valid
                } else {
                isEmpty = hiddenInput.value === '' || hiddenInput.value === 'N/A' || hiddenInput.value === 'Select Nationality' || hiddenInput.value === 'Select Program' || hiddenInput.value === 'Select Status';
                }
            }
        } else if (field.classList.contains('phone-group')) {
            // For phone groups
            const phoneInput = field.querySelector('input[type="tel"]');
            if (phoneInput) {
                isEmpty = phoneInput.value.trim() === '';
            }
        } else if (field.type === 'hidden') {
            // For hidden inputs (custom dropdowns)
            // Allow 'N/A' for suffix field, but not for other dropdowns
            if (field.id === 'suffix') {
                isEmpty = field.value === ''; // Only check for empty string, 'N/A' is valid
            } else {
            isEmpty = field.value === '' || field.value === 'N/A' || field.value === 'Select Nationality' || field.value === 'Select Program' || field.value === 'Select Status';
            }
        } else {
            // For regular inputs
            isEmpty = field.value.trim() === '';
        }
        
        if (isEmpty) {
            field.classList.add('validation-error');
            isValid = false;
        }
    });
    
    // Validate gender selection
    const genderRadios = document.querySelectorAll('input[name="gender"]');
    const genderSelected = Array.from(genderRadios).some(radio => radio.checked);
    console.log('Gender validation - radios found:', genderRadios.length);
    console.log('Gender validation - gender selected:', genderSelected);
    
    if (!genderSelected) {
        // Add validation error to all individual gender boxes
        const genderBoxes = document.querySelectorAll('.gender-box');
        console.log('Gender validation - gender boxes found:', genderBoxes.length);
        genderBoxes.forEach(box => {
            box.classList.add('validation-error');
        });
        console.log('Gender validation - added validation-error class to all gender boxes');
        isValid = false;
    } else {
        // Remove validation error from all gender boxes if gender is selected
        const genderBoxes = document.querySelectorAll('.gender-box');
        genderBoxes.forEach(box => {
            box.classList.remove('validation-error');
        });
        console.log('Gender validation - removed validation-error class from all gender boxes');
    }
    
    console.log('Step 1 validation completed. isValid:', isValid);
    
    // Show validation message if there are errors
    if (!isValid) {
        console.log('Showing validation message');
        showValidationMessage('Please complete the required fields on step 1');
    } else {
        console.log('All fields valid, proceeding to step 2');
    }
    
    // If validation passes, proceed to next step
    if (isValid) {
        proceedToStep2();
    }
}

function showValidationMessage(message) {
    // Remove any existing message
    const existingMessage = document.querySelector('.validation-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create validation message
    const messageDiv = document.createElement('div');
    messageDiv.className = 'validation-message';
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #ff6b6b;
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        text-align: center;
        font-weight: 500;
        z-index: 9999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    `;
    messageDiv.textContent = message;
    
    // Insert message at top of page
    document.body.appendChild(messageDiv);
    
    // Auto-remove message after 5 seconds
    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 5000);
}

function validateStep2() {
    let isValid = true;
    
    // Clear previous validation errors
    const allInputs = document.querySelectorAll('input, select, .custom-dropdown-group, .phone-group');
    allInputs.forEach(element => {
        element.classList.remove('validation-error');
    });
    
    // Remove any existing validation message
    const existingMessage = document.querySelector('.validation-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Validate fields in step 2 (exclude individual phone inputs for clean design)
    const allFields = document.querySelectorAll('#step2Form input, #step2Form select, #step2Form .custom-dropdown-group, #step2Form .phone-group');
    
    allFields.forEach(field => {
        let isEmpty = false;
        
        // Skip individual phone inputs for clean design
        if (field.type === 'tel') {
            console.log('Skipping phone field:', field.id || field.name || field.className);
            return;
        }
        
        // Skip optional fields (Address Line 2 and Postal Code)
        if (field.id === 'addressLine2' || field.id === 'postalCode') {
            console.log('Skipping optional field:', field.id || field.name || field.className);
            return;
        }
        
        console.log('Validating field:', field.id || field.name || field.className, 'Value:', field.value);
        
        // Special handling for middle name - check if "no middle name" checkbox is checked
        if (field.id === 'middleName') {
            const noMiddleNameCheckbox = document.getElementById('noMiddleName');
            console.log('Step 2 - Middle name validation - checkbox found:', !!noMiddleNameCheckbox);
            console.log('Step 2 - Middle name validation - checkbox checked:', noMiddleNameCheckbox ? noMiddleNameCheckbox.checked : 'checkbox not found');
            console.log('Step 2 - Middle name validation - field value:', field.value);
            
            if (noMiddleNameCheckbox && noMiddleNameCheckbox.checked) {
                // If checkbox is checked, middle name is not required
                field.classList.remove('validation-error');
                field.style.border = '';
                field.style.borderBottom = '';
                field.style.background = '';
                console.log('Step 2 - Middle name validation - checkbox checked, field is valid (no validation error)');
                return; // Skip validation for this field
            } else {
                // If checkbox is not checked, validate normally
                isEmpty = field.value.trim() === '';
                console.log('Step 2 - Middle name validation - checkbox not checked, field is empty:', isEmpty);
            }
        } else if (field.classList.contains('custom-dropdown-group')) {
            // For custom dropdowns
            const hiddenInput = field.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                // Allow 'N/A' for suffix field, but not for other dropdowns
                if (hiddenInput.id === 'suffix') {
                    isEmpty = hiddenInput.value === ''; // Only check for empty string, 'N/A' is valid
                } else {
                isEmpty = hiddenInput.value === '' || hiddenInput.value === 'N/A' || hiddenInput.value === 'Select Nationality' || hiddenInput.value === 'Select Program' || hiddenInput.value === 'Select Status';
                }
            }
        } else if (field.classList.contains('phone-group')) {
            // For phone groups
            const phoneInput = field.querySelector('input[type="tel"]');
            if (phoneInput) {
                isEmpty = phoneInput.value.trim() === '';
            }
        } else if (field.type === 'hidden') {
            // For hidden inputs (custom dropdowns)
            // Allow 'N/A' for suffix field, but not for other dropdowns
            if (field.id === 'suffix') {
                isEmpty = field.value === ''; // Only check for empty string, 'N/A' is valid
            } else {
            isEmpty = field.value === '' || field.value === 'N/A' || field.value === 'Select Nationality' || field.value === 'Select Program' || field.value === 'Select Status';
            }
        } else {
            // For regular inputs
            isEmpty = field.value.trim() === '';
        }
        
        if (isEmpty) {
            field.classList.add('validation-error');
            isValid = false;
        }
    });
    
    // Validate parent names - check for valid name characters only
    const motherName = document.getElementById('motherName');
    const fatherName = document.getElementById('fatherName');
    
    if (motherName && motherName.value.trim() !== '') {
        // Check if name contains only valid characters (letters, spaces, apostrophes, hyphens, periods)
        if (!/^[a-zA-Z\s'.-]+$/.test(motherName.value)) {
            motherName.classList.add('validation-error');
            showValidationMessage("Mother's name can only contain letters, spaces, apostrophes, hyphens, and periods. Numbers and other special characters are not allowed.");
            isValid = false;
        }
    }
    
    if (fatherName && fatherName.value.trim() !== '') {
        // Check if name contains only valid characters (letters, spaces, apostrophes, hyphens, periods)
        if (!/^[a-zA-Z\s'.-]+$/.test(fatherName.value)) {
            fatherName.classList.add('validation-error');
            showValidationMessage("Father's name can only contain letters, spaces, apostrophes, hyphens, and periods. Numbers and other special characters are not allowed.");
            isValid = false;
        }
    }
    
    // Validate gender selection
    const genderInputs = document.querySelectorAll('input[name="gender"]');
    let genderSelected = false;
    genderInputs.forEach(input => {
        if (input.checked) {
            genderSelected = true;
        }
    });
    
    if (!genderSelected) {
        // Highlight all gender boxes
        const genderBoxes = document.querySelectorAll('.gender-box');
        genderBoxes.forEach(box => {
            box.classList.add('validation-error');
        });
        isValid = false;
    }
    
    // Show validation message if there are errors
    if (!isValid) {
        if (!motherName?.classList.contains('validation-error') && !fatherName?.classList.contains('validation-error')) {
            showValidationMessage('Please complete the required fields on step 2');
        }
    }
    
    // If validation passes, proceed to next step
    if (isValid) {
        proceedToStep3();
    }
}

function proceedToStep2() {
	// Use unified step handler to ensure indicators and forms are synced
	showStep(2);
}

function proceedToStep3() {
	// Use unified step handler to ensure indicators and forms are synced
	showStep(3);
}

function proceedToStep4() {
    // Populate confirmation data
    populateConfirmationData();
    
    // Hide step 3 and show step 4
    document.getElementById('step3Form').style.display = 'none';
    document.getElementById('step4Form').style.display = 'block';
    
    // Update step indicator (if it exists)
    const step3Indicator = document.querySelector('.step-indicator .step-3');
    const step4Indicator = document.querySelector('.step-indicator .step-4');
    if (step3Indicator) step3Indicator.classList.remove('active');
    if (step4Indicator) step4Indicator.classList.add('active');
    
    currentStep = 4;
    console.log('Proceeded to Step 4 - Confirmation page');
}

function validateStep3() {
    const email = document.getElementById('email');
    const confirmEmail = document.getElementById('confirmEmail');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');

    let isValid = true;

    // Clear previous validation errors
    const allInputs = document.querySelectorAll('#step3Form input');
    allInputs.forEach(element => {
        element.classList.remove('validation-error');
        element.style.borderColor = '';
    });

    // Email validation
    if (!email.value.trim()) {
        email.classList.add('validation-error');
        isValid = false;
    }

    // Email confirmation validation
    if (email.value !== confirmEmail.value) {
        confirmEmail.classList.add('validation-error');
        isValid = false;
    }

    // Password validation
    if (!password.value.trim()) {
        password.classList.add('validation-error');
        isValid = false;
    }

    // Password confirmation validation
    if (password.value !== confirmPassword.value) {
        confirmPassword.classList.add('validation-error');
        isValid = false;
    }

    if (!isValid) {
        showValidationMessage('Please fill in all required fields and ensure passwords match.');
    } else {
        // Save form data and navigate to confirmation page
        saveFormDataAndNavigate();
    }

    return isValid;
}

function validateStep4() {
    const confirmCheckbox = document.getElementById('confirmDetailsCheckbox');
    
    if (!confirmCheckbox.checked) {
        showValidationMessage('Please confirm that all information is accurate before submitting.');
        return false;
    }
    
    return true;
}

function populateConfirmationData() {
    // Personal Information
    const firstName = document.getElementById('firstName').value;
    const lastName = document.getElementById('lastName').value;
    const middleName = document.getElementById('middleName').value;
    const suffix = document.getElementById('suffix').value;
    const dob = document.getElementById('dob').value;
    const gender = document.querySelector('input[name="gender"]:checked');
    const nationality = document.getElementById('nationality').value;
    const phoneNumber = document.getElementById('phoneNumber').value;
    
    // Build full name
    let fullName = firstName + ' ' + lastName;
    if (middleName && !document.getElementById('noMiddleName').checked) {
        fullName = firstName + ' ' + middleName + ' ' + lastName;
    }
    if (suffix && suffix !== 'N/A') {
        fullName += ' ' + suffix;
    }
    
    document.getElementById('confirmFullName').textContent = fullName;
    document.getElementById('confirmDOB').textContent = dob;
    document.getElementById('confirmGender').textContent = gender ? gender.value : '';
    document.getElementById('confirmNationality').textContent = nationality;
    document.getElementById('confirmPhone').textContent = phoneNumber;
    
    // Academic Information
    const program = document.getElementById('program').value;
    const educationalStatus = document.getElementById('educationalStatus').value;
    
    document.getElementById('confirmProgram').textContent = program;
    document.getElementById('confirmEducationalStatus').textContent = educationalStatus;
    
    // Address Information
    const country = document.getElementById('country').value;
    const cityProvince = document.getElementById('cityProvince').value;
    const municipality = document.getElementById('municipality').value;
    const baranggay = document.getElementById('baranggay').value;
    const address = document.getElementById('address').value;
    const postalCode = document.getElementById('postalCode').value;
    
    document.getElementById('confirmCountry').textContent = country;
    document.getElementById('confirmCityProvince').textContent = cityProvince;
    document.getElementById('confirmMunicipality').textContent = municipality;
    document.getElementById('confirmBaranggay').textContent = baranggay;
    document.getElementById('confirmAddress').textContent = address;
    document.getElementById('confirmPostalCode').textContent = postalCode;
    
    // Family Information
    const motherName = document.getElementById('motherName').value;
    const motherPhone = document.getElementById('motherPhoneNumber').value;
    const motherOccupation = document.getElementById('motherOccupation').value;
    const fatherName = document.getElementById('fatherName').value;
    const fatherPhone = document.getElementById('fatherPhoneNumber').value;
    const fatherOccupation = document.getElementById('fatherOccupation').value;
    
    document.getElementById('confirmMotherName').textContent = motherName;
    document.getElementById('confirmMotherPhone').textContent = motherPhone;
    document.getElementById('confirmMotherOccupation').textContent = motherOccupation;
    document.getElementById('confirmFatherName').textContent = fatherName;
    document.getElementById('confirmFatherPhone').textContent = fatherPhone;
    document.getElementById('confirmFatherOccupation').textContent = fatherOccupation;
    
    // Emergency Contact
    const emergencyName = document.getElementById('emergencyName').value;
    const emergencyPhone = document.getElementById('emergencyPhoneNumber').value;
    const emergencyAddress = document.getElementById('emergencyAddress').value;
    
    document.getElementById('confirmEmergencyName').textContent = emergencyName;
    document.getElementById('confirmEmergencyPhone').textContent = emergencyPhone;
    document.getElementById('confirmEmergencyAddress').textContent = emergencyAddress;
    
    // Account Information
    const email = document.getElementById('email').value;
    
    document.getElementById('confirmEmail').textContent = email;
}

// === FORM DATA RESTORATION ===

// Restore form data from sessionStorage when returning from confirmation page
function restoreFormDataFromSession() {
    try {
        const savedData = sessionStorage.getItem('studentRegistrationData');
        if (savedData) {
            const formData = JSON.parse(savedData);
            console.log('Restoring form data from sessionStorage:', formData);
            populateFormFields(formData);
        }
    } catch (error) {
        console.error('Error restoring form data:', error);
    }
}

// Populate form fields with saved data
function populateFormFields(data) {
    // Personal Information - Step 1
    if (data.firstName) document.getElementById('firstName').value = data.firstName;
    if (data.middleName) document.getElementById('middleName').value = data.middleName;
    if (data.lastName) document.getElementById('lastName').value = data.lastName;
    if (data.suffix) {
        const suffixSelect = document.getElementById('suffix');
        if (suffixSelect) {
            suffixSelect.value = data.suffix;
            // Update custom dropdown display
            const suffixDisplay = suffixSelect.parentElement.querySelector('.custom-dropdown-display');
            if (suffixDisplay) {
                suffixDisplay.textContent = data.suffix;
            }
        }
    }
    if (data.dob) document.getElementById('dob').value = data.dob;
    if (data.gender) {
        const genderInputs = document.querySelectorAll('input[name="gender"]');
        genderInputs.forEach(input => {
            if (input.value === data.gender) {
                input.checked = true;
            }
        });
    }
    if (data.nationality) {
        const nationalitySelect = document.getElementById('nationality');
        if (nationalitySelect) {
            nationalitySelect.value = data.nationality;
            const nationalityDisplay = nationalitySelect.parentElement.querySelector('.custom-dropdown-display');
            if (nationalityDisplay) {
                nationalityDisplay.textContent = data.nationality;
            }
        }
    }
    if (data.phoneNumber) {
        const phoneParts = data.phoneNumber.split(' ');
        if (phoneParts.length >= 3) {
            document.getElementById('phoneCountryCode').value = phoneParts[0];
            document.getElementById('phoneAreaCode').value = phoneParts[1];
            document.getElementById('phoneNumber').value = phoneParts.slice(2).join(' ');
        }
    }
    if (data.program) {
        const programSelect = document.getElementById('program');
        if (programSelect) {
            programSelect.value = data.program;
            const programDisplay = programSelect.parentElement.querySelector('.custom-dropdown-display');
            if (programDisplay) {
                programDisplay.textContent = data.program;
            }
        }
    }
    if (data.educationalStatus) {
        const eduStatusSelect = document.getElementById('educationalStatus');
        if (eduStatusSelect) {
            eduStatusSelect.value = data.educationalStatus;
            const eduStatusDisplay = eduStatusSelect.parentElement.querySelector('.custom-dropdown-display');
            if (eduStatusDisplay) {
                eduStatusDisplay.textContent = data.educationalStatus;
            }
        }
    }
    
    // Handle "no middle name" checkbox
    if (data.noMiddleName) {
        const noMiddleNameCheckbox = document.getElementById('noMiddleName');
        if (noMiddleNameCheckbox) {
            noMiddleNameCheckbox.checked = true;
            const middleNameInput = document.getElementById('middleName');
            if (middleNameInput) {
                middleNameInput.value = '';
                middleNameInput.disabled = true;
                middleNameInput.required = false;
            }
        }
    }
    
    // Address Information - Step 2
    if (data.country) {
        const countrySelect = document.getElementById('country');
        if (countrySelect) {
            countrySelect.value = data.country;
            const countryDisplay = countrySelect.parentElement.querySelector('.custom-dropdown-display');
            if (countryDisplay) {
                countryDisplay.textContent = data.country;
            }
        }
    }
    if (data.cityProvince) {
        const cityProvinceSelect = document.getElementById('cityProvince');
        if (cityProvinceSelect) {
            cityProvinceSelect.value = data.cityProvince;
            const cityProvinceDisplay = cityProvinceSelect.parentElement.querySelector('.custom-dropdown-display');
            if (cityProvinceDisplay) {
                cityProvinceDisplay.textContent = data.cityProvince;
            }
        }
    }
    if (data.municipality) {
        const municipalitySelect = document.getElementById('municipality');
        if (municipalitySelect) {
            municipalitySelect.value = data.municipality;
            const municipalityDisplay = municipalitySelect.parentElement.querySelector('.custom-dropdown-display');
            if (municipalityDisplay) {
                municipalityDisplay.textContent = data.municipality;
            }
        }
    }
    if (data.baranggay) document.getElementById('baranggay').value = data.baranggay;
    if (data.address) document.getElementById('address').value = data.address;
    if (data.addressLine2) document.getElementById('addressLine2').value = data.addressLine2;
    if (data.postalCode) document.getElementById('postalCode').value = data.postalCode;
    
    // Family Information - Step 2
    if (data.motherName) document.getElementById('motherName').value = data.motherName;
    if (data.motherPhoneNumber) {
        const motherPhoneParts = data.motherPhoneNumber.split(' ');
        if (motherPhoneParts.length >= 3) {
            document.getElementById('motherPhoneCountryCode').value = motherPhoneParts[0];
            document.getElementById('motherPhoneAreaCode').value = motherPhoneParts[1];
            document.getElementById('motherPhoneNumber').value = motherPhoneParts.slice(2).join(' ');
        }
    }
    if (data.motherOccupation) document.getElementById('motherOccupation').value = data.motherOccupation;
    if (data.fatherName) document.getElementById('fatherName').value = data.fatherName;
    if (data.fatherPhoneNumber) {
        const fatherPhoneParts = data.fatherPhoneNumber.split(' ');
        if (fatherPhoneParts.length >= 3) {
            document.getElementById('fatherPhoneCountryCode').value = fatherPhoneParts[0];
            document.getElementById('fatherPhoneAreaCode').value = fatherPhoneParts[1];
            document.getElementById('fatherPhoneNumber').value = fatherPhoneParts.slice(2).join(' ');
        }
    }
    if (data.fatherOccupation) document.getElementById('fatherOccupation').value = data.fatherOccupation;
    
    // Emergency Contact - Step 2
    if (data.emergencyName) document.getElementById('emergencyName').value = data.emergencyName;
    if (data.emergencyPhoneNumber) {
        const emergencyPhoneParts = data.emergencyPhoneNumber.split(' ');
        if (emergencyPhoneParts.length >= 3) {
            document.getElementById('emergencyPhoneCountryCode').value = emergencyPhoneParts[0];
            document.getElementById('emergencyPhoneAreaCode').value = emergencyPhoneParts[1];
            document.getElementById('emergencyPhoneNumber').value = emergencyPhoneParts.slice(2).join(' ');
        }
    }
    if (data.emergencyAddress) document.getElementById('emergencyAddress').value = data.emergencyAddress;
    
    // Account Information - Step 3
    if (data.email) document.getElementById('email').value = data.email;
    if (data.confirmEmail) document.getElementById('confirmEmail').value = data.confirmEmail;
    if (data.password) document.getElementById('password').value = data.password;
    if (data.confirmPassword) document.getElementById('confirmPassword').value = data.confirmPassword;
    
    console.log('Form fields populated with saved data');
}

// === HOME BUTTON CLEAR FUNCTIONALITY ===

// Clear form data when user clicks Home button
function initializeHomeButtonClear() {
    // Find all home buttons/links with more comprehensive selectors
    const homeButtons = document.querySelectorAll(`
        a[href*="landing.html"], 
        .back-to-home, 
        .admission-home-icon,
        a[title*="Home"],
        a[title*="Back to Home"],
        .breadcrumb-home
    `);
    
    console.log('Found', homeButtons.length, 'home buttons/links');
    
    homeButtons.forEach((button, index) => {
        console.log(`Home button ${index + 1}:`, button);
        button.addEventListener('click', function(e) {
            console.log('Home button clicked - clearing form data');
            // Clear sessionStorage when navigating to home
            try {
                sessionStorage.removeItem('studentRegistrationData');
                console.log('Form data cleared - navigating to home page');
            } catch (error) {
                console.error('Error clearing form data:', error);
            }
        });
    });
    
    // Also listen for beforeunload events to clear data when leaving the site
    window.addEventListener('beforeunload', function(e) {
        // Only clear if we're actually navigating away from the site
        if (window.location.href.includes('landing.html')) {
            try {
                sessionStorage.removeItem('studentRegistrationData');
                console.log('Form data cleared - leaving site');
            } catch (error) {
                console.error('Error clearing form data on unload:', error);
            }
        }
    });
}

// === CONFIRMATION CHECKBOX FUNCTIONALITY ===

function initializeConfirmationCheckbox() {
    const confirmCheckbox = document.getElementById('confirmDetailsCheckbox');
    const submitFinalBtn = document.getElementById('submitFinalBtn');
    
    if (confirmCheckbox && submitFinalBtn) {
        // Enable/disable submit button based on checkbox
        confirmCheckbox.addEventListener('change', function() {
            submitFinalBtn.disabled = !this.checked;
            if (this.checked) {
                submitFinalBtn.style.opacity = '1';
                submitFinalBtn.style.cursor = 'pointer';
            } else {
                submitFinalBtn.style.opacity = '0.6';
                submitFinalBtn.style.cursor = 'not-allowed';
            }
        });
    }
}

// Show submission success message
function showSubmissionSuccess() {
    // Remove any existing success message
    const existingMessage = document.querySelector('.submission-success-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create success message
    const successDiv = document.createElement('div');
    successDiv.className = 'submission-success-message';
    successDiv.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #28a745;
        color: white;
        padding: 20px 30px;
        border-radius: 12px;
        text-align: center;
        font-weight: 600;
        font-size: 1.1rem;
        z-index: 9999;
        box-shadow: 0 8px 32px rgba(40, 167, 69, 0.3);
        border: 2px solid #1e7e34;
    `;
    successDiv.innerHTML = `
        <div style="margin-bottom: 10px;">✅</div>
        <div>Application Submitted Successfully!</div>
        <div style="font-size: 0.9rem; margin-top: 8px; opacity: 0.9;">
            Your application has been received and is being processed.
        </div>
    `;
    
    // Insert message at top of page
    document.body.appendChild(successDiv);
    
    // Update the submit button to show success state
    const submitBtn = document.getElementById('submitFinalBtn');
    if (submitBtn) {
        submitBtn.textContent = 'Application Submitted ✓';
        submitBtn.style.background = '#28a745';
        submitBtn.disabled = true;
        submitBtn.style.cursor = 'default';
    }
    
    // Auto-remove message after 8 seconds
    setTimeout(() => {
        if (successDiv.parentNode) {
            successDiv.remove();
        }
    }, 8000);
    
    console.log('Application submitted successfully - confirmation details remain visible');
}

// Save form data and navigate to confirmation page
function saveFormDataAndNavigate() {
    // Collect all form data
    const formData = {
        // Personal Information
        firstName: document.getElementById('firstName')?.value || '',
        lastName: document.getElementById('lastName')?.value || '',
        middleName: document.getElementById('middleName')?.value || '',
        suffix: document.getElementById('suffix')?.value || '',
        dob: document.getElementById('dob')?.value || '',
        gender: document.querySelector('input[name="gender"]:checked')?.value || '',
        nationality: document.getElementById('nationality')?.value || '',
        phoneNumber: document.getElementById('phoneNumber')?.value || '',
        noMiddleName: document.getElementById('noMiddleName')?.checked || false,
        
        // Academic Information
        program: document.getElementById('program')?.value || '',
        educationalStatus: document.getElementById('educationalStatus')?.value || '',
        
        // Address Information
        country: document.getElementById('country')?.value || '',
        cityProvince: document.getElementById('cityProvince')?.value || '',
        municipality: document.getElementById('municipality')?.value || '',
        baranggay: document.getElementById('baranggay')?.value || '',
        address: document.getElementById('address')?.value || '',
        addressLine2: document.getElementById('addressLine2')?.value || '',
        postalCode: document.getElementById('postalCode')?.value || '',
        
        // Family Information
        motherName: document.getElementById('motherName')?.value || '',
        motherPhoneNumber: document.getElementById('motherPhoneNumber')?.value || '',
        motherOccupation: document.getElementById('motherOccupation')?.value || '',
        fatherName: document.getElementById('fatherName')?.value || '',
        fatherPhoneNumber: document.getElementById('fatherPhoneNumber')?.value || '',
        fatherOccupation: document.getElementById('fatherOccupation')?.value || '',
        
        // Emergency Contact
        emergencyName: document.getElementById('emergencyName')?.value || '',
        emergencyPhoneNumber: document.getElementById('emergencyPhoneNumber')?.value || '',
        emergencyAddress: document.getElementById('emergencyAddress')?.value || '',
        
        // Account Information
        email: document.getElementById('email')?.value || '',
        confirmEmail: document.getElementById('confirmEmail')?.value || '',
        password: document.getElementById('password')?.value || '',
        confirmPassword: document.getElementById('confirmPassword')?.value || ''
    };
    
    // Save to sessionStorage
    try {
        sessionStorage.setItem('studentRegistrationData', JSON.stringify(formData));
        console.log('Form data saved to sessionStorage');
        
        // Navigate to confirmation page
        window.location.href = 'confirmation-page.html';
    } catch (error) {
        console.error('Error saving form data:', error);
        showValidationMessage('Error saving form data. Please try again.');
    }
}
