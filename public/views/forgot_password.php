<?php
session_start();
require_once __DIR__ . '/../../config/dbconnection.php';

// User must be logged in OR have a reset session started
if (isset($_SESSION['professor_id'])) {
    $professorId = (int) $_SESSION['professor_id'];
    // Get professor info
    $stmt = $pdo->prepare("SELECT full_name, email FROM professor WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$professorId]);
    $professor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$professor) {
        header('Location: authorization.php');
        exit;
    }
} elseif (isset($_SESSION['password_reset_email']) && isset($_SESSION['password_reset_professor_id'])) {
    // Flow from login page where code was already sent
    $professor = [
        'email' => $_SESSION['password_reset_email'],
        // We might not have full_name here easily unless we fetch it, but email is what matters for display
        'full_name' => 'Professor' 
    ];
} else {
    header('Location: authorization.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Lozinke</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/colors.css">
    <style>
        .reset-container {
            max-width: 450px;
            margin: 60px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .step { display: none; }
        .step.active { display: block; }
        .code-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        .code-inputs input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            border: 2px solid #ddd;
            border-radius: 8px;
        }
        .code-inputs input:focus {
            border-color: #0d6efd;
            outline: none;
        }
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }
        .back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>

<div class="container">
    <div class="reset-container">
        <h3 class="text-center mb-4">Reset Lozinke</h3>
        
        <!-- Step 1: Send Code -->
        <?php 
        $step1Class = 'active';
        $step2Class = '';
        // If coming from login and code is already sent
        if (!isset($_SESSION['professor_id']) && isset($_SESSION['password_reset_code'])) {
            $step1Class = '';
            $step2Class = 'active';
        }
        ?>
        <div id="step1" class="step <?php echo $step1Class; ?>">
            <p class="text-muted text-center">
                Poslat ćemo verifikacioni kod na vašu email adresu:
                <br><strong><?php 
                    $emailParts = explode('@', $professor['email']);
                    echo substr($emailParts[0], 0, 2) . '***@' . $emailParts[1];
                ?></strong>
            </p>
            <div id="sendCodeError" class="alert alert-danger d-none"></div>
            <button id="sendCodeBtn" class="btn btn-primary w-100">
                <span class="btn-text">Pošalji kod</span>
                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
            </button>
        </div>

        <!-- Step 2: Enter Code -->
        <div id="step2" class="step <?php echo $step2Class; ?>">
            <p class="text-muted text-center">Unesite 6-cifreni kod koji ste primili na email</p>
            <div class="code-inputs">
                <input type="text" maxlength="1" class="code-digit" data-index="0" autofocus>
                <input type="text" maxlength="1" class="code-digit" data-index="1">
                <input type="text" maxlength="1" class="code-digit" data-index="2">
                <input type="text" maxlength="1" class="code-digit" data-index="3">
                <input type="text" maxlength="1" class="code-digit" data-index="4">
                <input type="text" maxlength="1" class="code-digit" data-index="5">
            </div>
            <div id="verifyCodeError" class="alert alert-danger d-none"></div>
            <button id="verifyCodeBtn" class="btn btn-primary w-100">
                <span class="btn-text">Verifikuj kod</span>
                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
            </button>
            <button id="resendCodeBtn" class="btn btn-link w-100 mt-2">Pošalji ponovo</button>
        </div>

        <!-- Step 3: New Password -->
        <div id="step3" class="step">
            <p class="text-muted text-center">Unesite novu lozinku</p>
            <div id="resetPasswordError" class="alert alert-danger d-none"></div>
            <div id="resetPasswordSuccess" class="alert alert-success d-none"></div>
            <div class="mb-3">
                <label for="newPassword" class="form-label">Nova lozinka</label>
                <input type="password" class="form-control" id="newPassword" minlength="8" required>
                <div class="form-text">Najmanje 8 karaktera</div>
            </div>
            <div class="mb-3">
                <label for="confirmPassword" class="form-label">Potvrdi lozinku</label>
                <input type="password" class="form-control" id="confirmPassword" minlength="8" required>
            </div>
            <button id="resetPasswordBtn" class="btn btn-success w-100">
                <span class="btn-text">Sačuvaj novu lozinku</span>
                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
            </button>
        </div>

        <a href="professor_panel.php" class="back-link">← Nazad na profil</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const API_URL = '../api/password_reset.php';

    // Step elements
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');

    // Buttons
    const sendCodeBtn = document.getElementById('sendCodeBtn');
    const verifyCodeBtn = document.getElementById('verifyCodeBtn');
    const resendCodeBtn = document.getElementById('resendCodeBtn');
    const resetPasswordBtn = document.getElementById('resetPasswordBtn');

    // Code inputs
    const codeDigits = document.querySelectorAll('.code-digit');

    // Error displays
    const sendCodeError = document.getElementById('sendCodeError');
    const verifyCodeError = document.getElementById('verifyCodeError');
    const resetPasswordError = document.getElementById('resetPasswordError');
    const resetPasswordSuccess = document.getElementById('resetPasswordSuccess');

    function showStep(stepNum) {
        step1.classList.remove('active');
        step2.classList.remove('active');
        step3.classList.remove('active');
        document.getElementById('step' + stepNum).classList.add('active');
    }

    function showLoading(btn, show) {
        const text = btn.querySelector('.btn-text');
        const spinner = btn.querySelector('.spinner-border');
        if (show) {
            text.classList.add('d-none');
            spinner.classList.remove('d-none');
            btn.disabled = true;
        } else {
            text.classList.remove('d-none');
            spinner.classList.add('d-none');
            btn.disabled = false;
        }
    }

    function showError(element, message) {
        element.textContent = message;
        element.classList.remove('d-none');
    }

    function hideError(element) {
        element.classList.add('d-none');
    }

    // Send code
    sendCodeBtn.addEventListener('click', function() {
        hideError(sendCodeError);
        showLoading(sendCodeBtn, true);

        const formData = new FormData();
        formData.append('action', 'send_reset_code');

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showLoading(sendCodeBtn, false);
            if (data.success) {
                showStep(2);
                codeDigits[0].focus();
            } else {
                showError(sendCodeError, data.message);
            }
        })
        .catch(error => {
            showLoading(sendCodeBtn, false);
            showError(sendCodeError, 'Greška pri slanju zahtjeva');
        });
    });

    // Code input handling
    codeDigits.forEach((input, index) => {
        input.addEventListener('input', function(e) {
            const value = e.target.value;
            if (value.length === 1 && index < 5) {
                codeDigits[index + 1].focus();
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                codeDigits[index - 1].focus();
            }
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const digits = paste.replace(/\D/g, '').split('').slice(0, 6);
            digits.forEach((digit, i) => {
                if (codeDigits[i]) codeDigits[i].value = digit;
            });
            if (digits.length > 0) {
                codeDigits[Math.min(digits.length, 5)].focus();
            }
        });
    });

    // Verify code
    verifyCodeBtn.addEventListener('click', function() {
        hideError(verifyCodeError);
        
        let code = '';
        codeDigits.forEach(input => code += input.value);
        
        if (code.length !== 6) {
            showError(verifyCodeError, 'Unesite svih 6 cifara');
            return;
        }

        showLoading(verifyCodeBtn, true);

        const formData = new FormData();
        formData.append('action', 'verify_reset_code');
        formData.append('code', code);

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showLoading(verifyCodeBtn, false);
            if (data.success) {
                showStep(3);
                document.getElementById('newPassword').focus();
            } else {
                showError(verifyCodeError, data.message);
            }
        })
        .catch(error => {
            showLoading(verifyCodeBtn, false);
            showError(verifyCodeError, 'Greška pri verifikaciji');
        });
    });

    // Resend code
    resendCodeBtn.addEventListener('click', function() {
        codeDigits.forEach(input => input.value = '');
        hideError(verifyCodeError);
        showStep(1);
        sendCodeBtn.click();
    });

    // Reset password
    resetPasswordBtn.addEventListener('click', function() {
        hideError(resetPasswordError);
        resetPasswordSuccess.classList.add('d-none');

        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        if (!newPassword || !confirmPassword) {
            showError(resetPasswordError, 'Unesite oba polja');
            return;
        }

        if (newPassword.length < 8) {
            showError(resetPasswordError, 'Lozinka mora imati najmanje 8 karaktera');
            return;
        }

        if (newPassword !== confirmPassword) {
            showError(resetPasswordError, 'Lozinke se ne poklapaju');
            return;
        }

        showLoading(resetPasswordBtn, true);

        const formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('new_password', newPassword);
        formData.append('confirm_password', confirmPassword);

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showLoading(resetPasswordBtn, false);
            if (data.success) {
                resetPasswordSuccess.textContent = data.message + ' Preusmjeravanje...';
                resetPasswordSuccess.classList.remove('d-none');
                resetPasswordBtn.disabled = true;
                setTimeout(() => {
                    window.location.href = 'professor_panel.php';
                }, 2000);
            } else {
                showError(resetPasswordError, data.message);
            }
        })
        .catch(error => {
            showLoading(resetPasswordBtn, false);
            showError(resetPasswordError, 'Greška pri promjeni lozinke');
        });
    });
});
</script>
</body>
</html>
