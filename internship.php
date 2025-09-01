<?php
require_once 'includes/db.php';

// Get internship ID from URL
$internship_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$internship_id) {
    header("Location: search.php");
    exit();
}

// Try to get internship from new table first, then fall back to old table
$internship = null;
$error = '';

try {
    // Try new internships table
    $stmt = $pdo->prepare("
        SELECT i.*, c.company_name, c.location as company_location, c.website, c.contact_person,
               cat.category_name,
               GROUP_CONCAT(DISTINCT s.skill_name) as required_skills
        FROM internships i
        JOIN companies c ON i.company_id = c.company_id
        JOIN categories cat ON i.category_id = cat.category_id
        LEFT JOIN internship_skills is_skills ON i.internship_id = is_skills.internship_id
        LEFT JOIN skills s ON is_skills.skill_id = s.skill_id
        WHERE i.internship_id = ? AND i.status = 'active'
        GROUP BY i.internship_id
    ");
    $stmt->execute([$internship_id]);
    $internship = $stmt->fetch();
    
    if (!$internship) {
        // Try old internship_offers table
        $stmt = $pdo->prepare("
            SELECT i.*, c.company_name, c.location as company_location, c.website, c.contact_person,
                   'General' as category_name,
                   GROUP_CONCAT(DISTINCT s.skill_name) as required_skills,
                   i.role as title, i.offer_id as internship_id
            FROM internship_offers i
            JOIN companies c ON i.company_id = c.company_id
            LEFT JOIN offer_skills os ON i.offer_id = os.offer_id
            LEFT JOIN skills s ON os.skill_id = s.skill_id
            WHERE i.offer_id = ?
            GROUP BY i.offer_id
        ");
        $stmt->execute([$internship_id]);
        $internship = $stmt->fetch();
    }
} catch (PDOException $e) {
    error_log("Error fetching internship: " . $e->getMessage());
    $error = "Error loading internship details.";
}

if (!$internship) {
    $error = "Internship not found or no longer available.";
}

// Check if user can apply (must be logged in student)
$can_apply = false;
$already_applied = false;
$application = null;

// Detect legacy offer (internship coming from internship_offers) and disable applying
$is_legacy_offer = false;
if ($internship) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM internships WHERE internship_id = ?");
        $chk->execute([$internship['internship_id']]);
        if ($chk->fetchColumn() == 0) {
            $is_legacy_offer = true;
        }
    } catch (PDOException $e) {
        // ignore
    }
}

if (isLoggedIn() && getUserRole() === 'student' && !$is_legacy_offer) {
    // Ensure the logged-in user has a student profile
    try {
        $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $student = $stmt->fetch();
    } catch (PDOException $e) {
        $student = null;
        error_log("Error checking student profile: " . $e->getMessage());
    }

    if (!$student) {
        // User does not have a student profile — cannot apply
        $can_apply = false;
        $error = "Please complete your student profile before applying to internships.";
    } else {
        $can_apply = true;

        // Check if already applied using the known student_id
        if ($internship) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT * FROM applications WHERE internship_id = ? AND student_id = ?"
                );
                $stmt->execute([$internship['internship_id'], $student['student_id']]);
                $application = $stmt->fetch();
                $already_applied = $application ? true : false;
            } catch (PDOException $e) {
                // Ignore error, assume not applied
                error_log("Error checking existing application: " . $e->getMessage());
            }
        }
    }
}

// Handle application submission
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply && !$already_applied) {

    try {
        if (empty($student) || empty($student['student_id'])) {
            throw new Exception("Student profile missing; cannot submit application");
        }

        // Insert application using known student_id
        $stmt = $pdo->prepare(
            "INSERT INTO applications (internship_id, student_id, cover_letter, status, applied_at) VALUES (?, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([
            $internship['internship_id'],
            $student['student_id'],
            sanitize($_POST['cover_letter'])
        ]);

        $success = "Your application has been submitted successfully!";
        $already_applied = true;

        // Fetch the newly created application for display
        $stmt = $pdo->prepare("
            SELECT a.* FROM applications a
            JOIN students s ON a.student_id = s.student_id
            WHERE a.internship_id = ? AND s.user_id = ?
        ");
        $stmt->execute([$internship['internship_id'], $_SESSION['user_id']]);
        $application = $stmt->fetch();
    } catch (Exception $e) {
        // Surface the DB error temporarily to help debugging
        $error = "Error submitting application. Please try again. (" . $e->getMessage() . ")";
        error_log("Application error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $internship ? htmlspecialchars($internship['title']) : 'Internship'; ?> - InternLink</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h1>InternLink</h1>
            <div>
                <a href="index.php">Home</a>
                <a href="search.php">Search</a>
                <?php if (isLoggedIn()): ?>
                    <a href="<?php echo getUserRole(); ?>/dashboard.php">Dashboard</a>
                    <a href="logout.php">Logout</a>
                <?php else: ?>
                    <a href="login.php">Login</a>
                    <a href="register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!$internship && !$error): ?>
            <div class="alert alert-warning">
                <p>Internship not found or no longer available.</p>
                <a href="search.php" class="btn btn-primary">Browse Other Internships</a>
            </div>
        <?php endif; ?>

        <?php if ($internship): ?>
            <div class="internship-details">
                <!-- Header -->
                <div class="details-header">
                    <h2><?php echo htmlspecialchars($internship['title']); ?></h2>
                    <div class="company-info">
                        <h3><?php echo htmlspecialchars($internship['company_name']); ?></h3>
                        <?php if ($internship['website']): ?>
                            <a href="<?php echo htmlspecialchars($internship['website']); ?>" target="_blank" class="website-link">
                                Visit Website
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Main Details -->
                <div class="details-grid">
                    <div class="details-main card">
                        <div class="detail-group">
                            <h4>Description</h4>
                            <p><?php echo nl2br(htmlspecialchars($internship['description'])); ?></p>
                        </div>

                        <div class="detail-group">
                            <h4>Requirements</h4>
                            <p><?php echo nl2br(htmlspecialchars($internship['requirements'])); ?></p>
                        </div>

                        <?php if ($internship['required_skills']): ?>
                            <div class="detail-group">
                                <h4>Required Skills</h4>
                                <div class="skills-list">
                                    <?php foreach (explode(',', $internship['required_skills']) as $skill): ?>
                                        <span class="badge"><?php echo htmlspecialchars(trim($skill)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="details-sidebar">
                        <div class="card">
                            <h4>Quick Info</h4>
                            <ul class="quick-info">
                                <li>
                                    <span class="icon">📍</span>
                                    <span>Location: <?php echo htmlspecialchars($internship['location']); ?></span>
                                </li>
                                <li>
                                    <span class="icon">⏱️</span>
                                    <span>Duration: <?php echo htmlspecialchars($internship['duration']); ?></span>
                                </li>
                                <li>
                                    <span class="icon">💰</span>
                                    <span>Stipend: <?php echo htmlspecialchars($internship['stipend']); ?></span>
                                </li>
                                <li>
                                    <span class="icon">📅</span>
                                    <span>Posted: <?php 
                                        if (isset($internship['posted_at']) && $internship['posted_at']) {
                                            echo date('M d, Y', strtotime($internship['posted_at']));
                                        } elseif (isset($internship['created_at']) && $internship['created_at']) {
                                            echo date('M d, Y', strtotime($internship['created_at']));
                                        } else {
                                            echo 'N/A';
                                        }
                                    ?></span>
                                </li>
                                <li>
                                    <span class="icon">⏰</span>
                                    <span>Deadline: <?php echo date('M d, Y', strtotime($internship['deadline'])); ?></span>
                                </li>
                                <li>
                                    <span class="icon">📂</span>
                                    <span>Category: <?php echo htmlspecialchars($internship['category_name']); ?></span>
                                </li>
                            </ul>
                        </div>

                        <?php if ($internship['status'] === 'active' || $internship['status'] === 'open'): ?>
                            <?php if ($can_apply): ?>
                                <?php if ($already_applied): ?>
                                    <div class="card application-status">
                                        <h4>Application Status</h4>
                                        <p class="status status-<?php echo $application['status']; ?>">
                                            <?php echo ucfirst($application['status']); ?>
                                        </p>
                                        <p class="applied-date">
                                            Applied on <?php echo date('M d, Y', strtotime($application['applied_at'])); ?>
                                        </p>
                                        <?php if ($application['cover_letter']): ?>
                                            <div class="cover-letter-preview">
                                                <h5>Your Cover Letter:</h5>
                                                <p><?php echo nl2br(htmlspecialchars($application['cover_letter'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="card">
                                        <h4>Apply Now</h4>
                                        <form method="POST" action="" id="applicationForm">
                                            <div class="form-group">
                                                <label for="cover_letter">Cover Letter:</label>
                                                <textarea id="cover_letter" name="cover_letter" rows="6" required placeholder="Explain why you're a good fit for this role..."></textarea>
                                                <p class="help-text">Write at least 100 characters</p>
                                            </div>
                                            <button type="submit" class="btn btn-primary">Submit Application</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php elseif (!isLoggedIn()): ?>
                                <div class="card">
                                    <p>Please <a href="login.php">login</a> or <a href="register.php">register</a> as a student to apply.</p>
                                </div>
                            <?php else: ?>
                                <div class="card">
                                    <p>Only students can apply for internships.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="card">
                                <p class="status status-closed">This internship is no longer accepting applications.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .internship-details {
            margin-top: 2rem;
        }
        .details-header {
            margin-bottom: 2rem;
            text-align: center;
        }
        .details-header h2 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        .company-info {
            margin-top: 0.5rem;
            color: #666;
        }
        .company-info h3 {
            margin: 0;
            color: #007bff;
        }
        .website-link {
            display: inline-block;
            margin-top: 0.5rem;
            color: #007bff;
            text-decoration: none;
        }
        .website-link:hover {
            text-decoration: underline;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        .details-main {
            padding: 2rem;
        }
        .details-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .detail-group {
            margin-bottom: 2rem;
        }
        .detail-group h4 {
            margin-bottom: 0.5rem;
            color: #333;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.5rem;
        }
        .quick-info {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .quick-info li {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .quick-info .icon {
            margin-right: 0.5rem;
            width: 24px;
            text-align: center;
        }
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .badge {
            background-color: #e9ecef;
            color: #333;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.875rem;
        }
        .status {
            padding: 0.5rem;
            border-radius: 3px;
            text-align: center;
            margin: 1rem 0;
            font-weight: bold;
        }
        .status-pending {
            background-color: #ffd700;
            color: #000;
        }
        .status-accepted {
            background-color: #28a745;
            color: white;
        }
        .status-rejected {
            background-color: #dc3545;
            color: white;
        }
        .status-closed {
            background-color: #6c757d;
            color: white;
        }
        .applied-date {
            color: #666;
            font-size: 0.875rem;
            text-align: center;
        }
        .cover-letter-preview {
            margin-top: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .cover-letter-preview h5 {
            margin-bottom: 0.5rem;
            color: #333;
        }
        .help-text {
            font-size: 0.875rem;
            color: #666;
            margin-top: 0.25rem;
        }
        .application-status {
            text-align: center;
        }
        @media (max-width: 768px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
            .details-header {
                text-align: left;
            }
        }
    </style>

    <script>
        // Form validation
        document.getElementById('applicationForm')?.addEventListener('submit', function(e) {
            const coverLetter = document.getElementById('cover_letter').value.trim();
            
            if (coverLetter.length < 100) {
                e.preventDefault();
                alert('Please write a cover letter of at least 100 characters');
                return;
            }
            
            if (!confirm('Are you sure you want to submit this application?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
