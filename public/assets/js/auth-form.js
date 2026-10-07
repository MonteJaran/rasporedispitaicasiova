document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    const tabs = document.getElementById('tabs');
    const stacks = document.getElementById('stacks');
    const tabSignin = document.getElementById('tab-signin');
    const tabSignup = document.getElementById('tab-signup');
    const signinForm = document.getElementById('signin');
    const signupForm = document.getElementById('signup');

    // Tab switching
    function switchTab(tabName) {
        tabs.setAttribute('data-active', tabName);
        stacks.setAttribute('data-active', tabName);

        if (tabName === 'signin') {
            tabSignin.setAttribute('aria-selected', 'true');
            tabSignup.setAttribute('aria-selected', 'false');
        } else {
            tabSignin.setAttribute('aria-selected', 'false');
            tabSignup.setAttribute('aria-selected', 'true');
        }

        // Clear any existing errors when switching
        clearErrors();
    }

    tabSignin.addEventListener('click', () => switchTab('signin'));
    tabSignup.addEventListener('click', () => switchTab('signup'));

    // Password visibility toggle
    const toggleButtons = document.querySelectorAll('.toggle-eye');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-toggle');
            const passwordInput = document.getElementById(targetId);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.textContent = '😄';
                this.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                this.textContent = '👁️';
                this.setAttribute('aria-label', 'Show password');
            }
        });
    });

    // Password strength meter
    const passwordInput = document.getElementById('su-password');
    const pwdMeter = document.getElementById('pwd-meter');

    function calculatePasswordStrength(password) {
        let score = 0;
        if (password.length >= 6) score += 1;
        if (password.length >= 10) score += 1;
        if (/[a-z]/.test(password)) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^A-Za-z0-9]/.test(password)) score += 1;
        return Math.min(score, 4);
    }

    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const strength = calculatePasswordStrength(this.value);
            pwdMeter.className = '';

            if (this.value.length === 0) {
                pwdMeter.className = '';
            } else if (strength <= 1) {
                pwdMeter.className = 'weak';
            } else if (strength <= 2) {
                pwdMeter.className = 'fair';
            } else if (strength <= 3) {
                pwdMeter.className = 'good';
            } else {
                pwdMeter.className = 'strong';
            }
        });
    }

    // Form validation
    function showError(inputId, message) {
        const errorElement = document.querySelector(`[data-error-for="${inputId}"]`);
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }
    }

    function clearErrors() {
        const errorElements = document.querySelectorAll('.error');
        errorElements.forEach(el => {
            el.textContent = '';
            el.style.display = 'none';
        });
    }

    function validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    function validateForm(form) {
        clearErrors();
        let isValid = true;

        const inputs = form.querySelectorAll('input[required]');
        inputs.forEach(input => {
            if (!input.value.trim()) {
                showError(input.id, 'This field is required');
                isValid = false;
            } else if (input.type === 'email' && !validateEmail(input.value)) {
                showError(input.id, 'Please enter a valid email address');
                isValid = false;
            } else if (input.type === 'password' && input.value.length < 6) {
                showError(input.id, 'Password must be at least 6 characters long');
                isValid = false;
            }
        });

        // Additional validation for signup form
        if (form.id === 'signup') {
            const passwordEl = document.getElementById('su-password');
            const confirmEl = document.getElementById('su-confirm');
            const termsCheckbox = document.getElementById('su-terms');

            if (passwordEl && confirmEl) {
                const password = passwordEl.value;
                const confirmPassword = confirmEl.value;
                
                if (password && confirmPassword && password !== confirmPassword) {
                    showError('su-confirm', 'Passwords do not match');
                    isValid = false;
                }
            }

            // Only validate terms if checkbox exists
            if (termsCheckbox && !termsCheckbox.checked) {
                showError('su-terms', 'You must accept the Terms of Use');
                isValid = false;
            }
        }

        return isValid;
    }

    // Form submission handlers
    if (signinForm) {
        signinForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    }

    if (signupForm) {
        signupForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    }
});