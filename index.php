<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$message = "";
$messageType = ""; // 'success', 'danger', 'warning', 'info'
$stats = [
    'found' => 0,
    'deleted' => 0
];

// Check if IMAP extension is loaded
if (!function_exists('imap_open')) {
    $message = "The PHP IMAP extension is not installed or enabled. Please enable it in php.ini.";
    $messageType = "danger";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('imap_open')) {
    
    // 1. Sanitize and Retrieve Inputs
    $host = filter_var($_POST['host'], FILTER_SANITIZE_STRING);
    $port = filter_var($_POST['port'], FILTER_SANITIZE_NUMBER_INT);
    $protocol = filter_var($_POST['protocol'], FILTER_SANITIZE_STRING); 
    $encryption = filter_var($_POST['encryption'], FILTER_SANITIZE_STRING); 
    $username = filter_var($_POST['username'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password']; 
    
    // Sender Logic
    $senderScope = $_POST['sender_scope']; // 'all' or 'specific'
    $senderEmail = "";
    if ($senderScope === 'specific') {
        $senderEmail = filter_var($_POST['sender_email'], FILTER_SANITIZE_EMAIL);
    }

    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $subjectText = trim($_POST['subject_text']);
    $dryRun = isset($_POST['dry_run']); 

    // 2. Construct Connection String
    $mailboxStr = "{" . $host . ":" . $port . "/" . $protocol;
    if ($encryption !== 'none') {
        $mailboxStr .= "/" . $encryption;
    }
    if ($encryption == 'ssl' || $encryption == 'tls') {
        $mailboxStr .= "/novalidate-cert"; 
    }
    $mailboxStr .= "}INBOX";

    // 3. Attempt Connection
    try {
        // Suppress errors with @ to handle them manually via try/catch
        $inbox = @imap_open($mailboxStr, $username, $password);
        
        if (!$inbox) {
            throw new Exception("Connection failed: " . imap_last_error());
        }

        // 4. Build Search Criteria
        // IMAP search dates require specific format: 01-Jan-2023
        $sinceDate = date('d-M-Y', strtotime($startDate));
        
        // For "Before", IMAP excludes the date. To include the End Date, 
        // we search for BEFORE (End Date + 1 day).
        $endDateObj = new DateTime($endDate);
        $endDateObj->modify('+1 day');
        $beforeDate = date('d-M-Y', $endDateObj->getTimestamp());

        // Start criteria with dates
        $criteria = 'SINCE "' . $sinceDate . '" BEFORE "' . $beforeDate . '"';

        // Add Sender Filter (Only if 'specific' is chosen)
        if ($senderScope === 'specific' && !empty($senderEmail)) {
            $criteria = 'FROM "' . $senderEmail . '" ' . $criteria;
        }

        // Add optional subject filter
        if (!empty($subjectText)) {
            $criteria .= ' SUBJECT "' . $subjectText . '"';
        }

        // 5. Search Emails
        $emails = imap_search($inbox, $criteria);

        if ($emails) {
            $stats['found'] = count($emails);
            
            // Sort emails
            rsort($emails);

            if (!$dryRun) {
                // 6. Process Deletion (Actual)
                foreach ($emails as $email_number) {
                    imap_delete($inbox, $email_number);
                }
                
                imap_expunge($inbox); // Permanently remove
                $stats['deleted'] = $stats['found'];
                
                $msgSenderPart = ($senderScope === 'specific') ? "from <strong>$senderEmail</strong>" : "from <strong>ALL senders</strong>";
                $message = "Success! Deleted <strong>{$stats['deleted']}</strong> emails $msgSenderPart in the selected date range.";
                $messageType = "success";
            } else {
                // 6. Process Dry Run (Simulation)
                $msgSenderPart = ($senderScope === 'specific') ? "from <strong>$senderEmail</strong>" : "from <strong>ALL senders</strong>";
                $message = "<strong>Dry Run:</strong> Found <strong>{$stats['found']}</strong> emails $msgSenderPart. No emails were deleted.";
                $messageType = "warning";
            }
        } else {
            $message = "No emails found matching your criteria.";
            $messageType = "info";
        }

        // Close Connection
        imap_close($inbox);

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webmail Bulk Cleaner</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 800px; margin-top: 50px; }
        .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .stats-card { border-left: 5px solid #0d6efd; }
        /* Transition for smooth showing/hiding */
        #specific-sender-group { transition: all 0.3s ease-in-out; }
    </style>
</head>
<body>

<div class="container main-container">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <h2 class="text-center mb-4">📧 Webmail Bulk Cleaner</h2>

            <!-- Alert Box -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Main Form -->
            <div class="card p-4">
                <form method="POST" action="">
                    
                    <!-- Connection Settings -->
                    <h5 class="border-bottom pb-2 mb-3">1. Connection Settings</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Host</label>
                            <input type="text" name="host" class="form-control" placeholder="imap.gmail.com" required value="<?php echo isset($_POST['host']) ? htmlspecialchars($_POST['host']) : ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Port</label>
                            <input type="number" name="port" class="form-control" placeholder="993" value="<?php echo isset($_POST['port']) ? htmlspecialchars($_POST['port']) : '993'; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Encryption</label>
                            <select name="encryption" class="form-select">
                                <option value="ssl" selected>SSL</option>
                                <option value="tls">TLS</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Protocol</label>
                            <select name="protocol" class="form-select">
                                <option value="imap" selected>IMAP</option>
                                <option value="pop3">POP3</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email / Username</label>
                            <input type="email" name="username" class="form-control" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                            <div class="form-text">Your password is not stored.</div>
                        </div>
                    </div>

                    <!-- Filter Settings -->
                    <h5 class="border-bottom pb-2 mb-3">2. Filter Criteria</h5>
                    
                    <div class="row g-3 mb-3">
                        <!-- SENDER SCOPE SELECTOR -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Sender Scope</label>
                            <select name="sender_scope" id="senderScopeSelect" class="form-select">
                                <option value="all">All Senders (Any email)</option>
                                <option value="specific" <?php echo (isset($_POST['sender_scope']) && $_POST['sender_scope'] == 'specific') ? 'selected' : ''; ?>>Specific Sender Only</option>
                            </select>
                        </div>

                        <!-- SPECIFIC EMAIL INPUT (Hidden by default via JS) -->
                        <div class="col-md-12 d-none" id="specific-sender-group">
                            <label class="form-label">Specific Email Address</label>
                            <input type="email" name="sender_email" id="senderEmailInput" class="form-control" placeholder="e.g. newsletter@spam.com" value="<?php echo isset($_POST['sender_email']) ? htmlspecialchars($_POST['sender_email']) : ''; ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Start Date (Inclusive)</label>
                            <input type="date" name="start_date" class="form-control" required value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date (Inclusive)</label>
                            <input type="date" name="end_date" class="form-control" required value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Subject Keyword (Optional)</label>
                            <input type="text" name="subject_text" class="form-control" placeholder="e.g. Invoice, Update..." value="<?php echo isset($_POST['subject_text']) ? htmlspecialchars($_POST['subject_text']) : ''; ?>">
                            <div class="form-text">Leave empty to ignore subject.</div>
                        </div>
                    </div>

                    <!-- Action Settings -->
                    <h5 class="border-bottom pb-2 mb-3">3. Actions</h5>
                    
                    <div class="form-check form-switch mb-4 p-3 bg-light rounded border">
                        <input class="form-check-input" type="checkbox" id="dryRunCheck" name="dry_run" checked>
                        <label class="form-check-label fw-bold text-warning" for="dryRunCheck">
                            Test Mode (Dry Run) - Do NOT delete emails
                        </label>
                        <div class="form-text">Uncheck this to actually delete the emails. Recommended to run with this checked first.</div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <?php echo (isset($_POST['dry_run']) && $_POST['dry_run']) ? '🔍 Search & Count' : '🗑️ Search & Delete'; ?>
                        </button>
                    </div>

                </form>
            </div>

            <!-- Counters / Status -->
            <?php if ($stats['found'] > 0): ?>
            <div class="card mt-4 stats-card bg-white">
                <div class="card-body">
                    <h5 class="card-title">Session Results</h5>
                    <div class="row text-center">
                        <div class="col-6 border-end">
                            <h3 class="text-primary"><?php echo $stats['found']; ?></h3>
                            <p class="text-muted mb-0">Emails Found</p>
                        </div>
                        <div class="col-6">
                            <h3 class="text-<?php echo $stats['deleted'] > 0 ? 'danger' : 'secondary'; ?>"><?php echo $stats['deleted']; ?></h3>
                            <p class="text-muted mb-0">Emails Deleted</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-center mt-4 mb-5 text-muted small">
                <p>⚠️ Use with caution. Deleted emails cannot be recovered easily.</p>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Script to handle UI toggles -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const senderScopeSelect = document.getElementById('senderScopeSelect');
        const specificSenderGroup = document.getElementById('specific-sender-group');
        const senderEmailInput = document.getElementById('senderEmailInput');

        function toggleSenderInput() {
            if (senderScopeSelect.value === 'specific') {
                specificSenderGroup.classList.remove('d-none');
                senderEmailInput.setAttribute('required', 'required');
            } else {
                specificSenderGroup.classList.add('d-none');
                senderEmailInput.removeAttribute('required');
                senderEmailInput.value = ''; // Clear value if hidden
            }
        }

        // Run on load
        toggleSenderInput();

        // Run on change
        senderScopeSelect.addEventListener('change', toggleSenderInput);
    });
</script>

</body>
</html>