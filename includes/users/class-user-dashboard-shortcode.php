<?php
/**
 * User Dashboard Shortcode
 * 
 * @package Hello_Theme_Child
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class User_Dashboard_Shortcode {
    /**
     * Default shortcode attributes
     *
     * @var array
     */
    private $defaults = array(
        'vehicle_type' => 'default', // Options: default, private, truck, motorcycle
        'show_practice' => 'true',
        'show_real_test' => 'true',
        'show_teacher_quizzes' => 'true',
        'show_study_materials' => 'true',
        'show_topic_tests' => 'true',
        'show_stats' => 'true',
        'practice_url' => '#',
        'real_test_url' => '#',
        'study_materials_url' => '#',
        'topic_tests_url' => '#',
        'account_url' => '#',
        'stats_url' => '#',
        'welcome_text' => 'שלום, %s!', // %s will be replaced with user's name
        'track_name' => 'חינוך תעבורתי',
        'show_logout' => 'true',
        'teacher_quiz_limit' => '5'
    );

    /**
     * Vehicle type labels
     *
     * @var array
     */
    private $vehicle_types = array(
        'default' => 'שינוי נושא לימוד',
        'private' => 'רכב פרטי',
        'truck' => 'משאית',
        'motorcycle' => 'אפנוע או קורקינט'
    );

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode('user_dashboard', array($this, 'render_dashboard'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Enqueue styles
     */
    public function enqueue_styles() {
        global $post;
        
        // Check if we're on a page with the shortcode or on a single course page
        $should_enqueue = false;
        
        if (is_singular() && $post) {
            $should_enqueue = has_shortcode($post->post_content, 'user_dashboard') || 
                            (function_exists('sfwd_lms_has_access') && 'sfwd-courses' === $post->post_type);
        }
        
        if ($should_enqueue) {
            wp_enqueue_style(
                'user-dashboard-style',
                get_stylesheet_directory_uri() . '/assets/css/user-dashboard.css',
                array(),
                filemtime(get_stylesheet_directory() . '/assets/css/user-dashboard.css')
            );
        }
    }

    /**
     * Get current user's full name
     */
    private function get_user_full_name() {
        $current_user = wp_get_current_user();
        $name = trim($current_user->first_name . ' ' . $current_user->last_name);
        return !empty($name) ? $name : $current_user->display_name;
    }

    /**
     * Get vehicle type text
     *
     * @param string $type Vehicle type key
     * @return string Vehicle type label
     */
    private function get_vehicle_type_text($type) {
        return isset($this->vehicle_types[$type]) ? $this->vehicle_types[$type] : $this->vehicle_types['default'];
    }

    /**
     * Get current date in format dd/mm/yyyy
     */
    private function get_current_date() {
        return date('d/m/Y');
    }

    /**
     * Get the teacher ID assigned to current student
     *
     * @return int|false Teacher ID or false if not found
     */
    private function get_student_teacher_id() {
        if (!is_user_logged_in()) {
            return false;
        }

        $current_user_id = get_current_user_id();
        global $wpdb;
        
        // Try to get teacher from school_teacher_students table
        $teacher_id = $wpdb->get_var($wpdb->prepare(
            "SELECT teacher_id 
             FROM {$wpdb->prefix}school_teacher_students 
             WHERE student_id = %d 
             LIMIT 1",
            $current_user_id
        ));

        // If no direct teacher-student relationship, try to get from class
        if (!$teacher_id) {
            $teacher_id = $wpdb->get_var($wpdb->prepare(
                "SELECT sc.teacher_id 
                 FROM {$wpdb->prefix}school_classes sc
                 JOIN {$wpdb->prefix}school_students ss ON sc.id = ss.class_id
                 WHERE ss.wp_user_id = %d
                 LIMIT 1",
                $current_user_id
            ));
        }

        return $teacher_id ? (int)$teacher_id : false;
    }

    /**
     * Get quizzes created by a specific teacher
     *
     * @param int $teacher_id Teacher user ID
     * @param int $limit Number of quizzes to retrieve
     * @return array Quiz information
     */
    private function get_teacher_quizzes($teacher_id, $limit = 5) {
        $args = array(
            'post_type'      => 'sfwd-quiz',
            'posts_per_page' => intval($limit),
            'author'         => $teacher_id,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $quizzes = get_posts($args);
        
        // Add quiz metadata
        foreach ($quizzes as &$quiz) {
            $quiz->quiz_url = get_permalink($quiz->ID);
            $quiz->quiz_date = get_the_date('d/m/Y', $quiz->ID);
        }
        
        return $quizzes;
    }

    /**
     * Get teacher's name
     *
     * @param int $teacher_id Teacher user ID
     * @return string Teacher's display name
     */
    private function get_teacher_name($teacher_id) {
        $teacher = get_userdata($teacher_id);
        return $teacher ? $teacher->display_name : '';
    }

    /**
     * Get user's course access status using user meta
     *
     * @return array Course access information
     */
    private function get_user_course_access() {
        if (!is_user_logged_in()) {
            return array();
        }

        $user_id = get_current_user_id();
        $current_time = current_time('timestamp');
        
        // Get all user meta keys that match course expiry pattern
        $all_meta = get_user_meta($user_id);
        $access_info = array(
            'has_active' => false,
            'has_expired' => false,
            'active_courses' => array(),
            'expired_courses' => array(),
            'expiring_soon' => array()
        );

        foreach ($all_meta as $meta_key => $meta_values) {
            // Look for course expiry meta keys: course_{courseId}_access_expires
            if (preg_match('/^course_(\d+)_access_expires$/', $meta_key, $matches)) {
                $course_id = intval($matches[1]);
                $expires_timestamp = intval($meta_values[0]);
                
                if ($expires_timestamp <= 0) continue; // Skip if no expiry set
                
                $course = get_post($course_id);
                if (!$course) continue;
                
                $course_info = array(
                    'id' => $course_id,
                    'title' => $course->post_title,
                    'url' => get_permalink($course_id),
                    'expires' => $expires_timestamp,
                    'expires_formatted' => date_i18n(get_option('date_format'), $expires_timestamp),
                    'product_id' => 0, // We'll try to find this
                    'days_remaining' => max(0, ceil(($expires_timestamp - $current_time) / DAY_IN_SECONDS))
                );

                // Try to find associated product ID
                $order_id_key = "course_{$course_id}_order_id";
                if (isset($all_meta[$order_id_key])) {
                    $order_id = intval($all_meta[$order_id_key][0]);
                    $order = wc_get_order($order_id);
                    if ($order) {
                        foreach ($order->get_items() as $item) {
                            $product_id = $item->get_product_id();
                            // Check if this product is associated with the course
                            $product_courses = get_post_meta($product_id, '_learndash_courses', true);
                            if (is_array($product_courses) && in_array($course_id, $product_courses)) {
                                $course_info['product_id'] = $product_id;
                                break;
                            }
                        }
                    }
                }

                if ($expires_timestamp > $current_time) {
                    $access_info['has_active'] = true;
                    $access_info['active_courses'][] = $course_info;
                    
                    // Check if expiring within 7 days
                    if ($course_info['days_remaining'] <= 7) {
                        $access_info['expiring_soon'][] = $course_info;
                    }
                } else {
                    $access_info['has_expired'] = true;
                    $access_info['expired_courses'][] = $course_info;
                }
            }
        }

        return $access_info;
    }

    /**
     * Render course access status section
     *
     * @param array $access_info Course access information
     * @return string HTML output
     */
    private function render_course_access_status($access_info) {
        if (empty($access_info['active_courses']) && empty($access_info['expired_courses'])) {
            return '';
        }

        ob_start();
        ?>
        <div class="course-access-section">
            <div class="column-header">
                <h3>מצב גישה לקורסים</h3>
            </div>
            
            <?php if (!empty($access_info['expiring_soon'])) : ?>
                <div class="access-notice expiring-notice">
                    <h4>⚠️ גישה פגה בקרוב</h4>
                    <?php foreach ($access_info['expiring_soon'] as $course) : ?>
                        <div class="course-expiry-item">
                            <strong><?php echo esc_html($course['title']); ?></strong>
                            <span class="expiry-text">פג תוקף בעוד <?php echo $course['days_remaining']; ?> ימים (<?php echo esc_html($course['expires_formatted']); ?>)</span>
                            <?php if ($course['product_id']) : 
                                $product = wc_get_product($course['product_id']);
                                if ($product) : ?>
                                    <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="renew-button">חדש מנוי</a>
                                <?php endif;
                            endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($access_info['expired_courses'])) : ?>
                <div class="access-notice expired-notice">
                    <h4>🚫 גישה פגה</h4>
                    <p>הגישה שלך לקורסים הבאים פגה. חדש את המנוי כדי להמשיך ללמוד:</p>
                    <?php foreach ($access_info['expired_courses'] as $course) : ?>
                        <div class="course-expiry-item expired">
                            <div class="expired-course-header">
                                <strong><?php echo esc_html($course['title']); ?></strong>
                                <span class="expiry-text">פג תוקף ב-<?php echo esc_html($course['expires_formatted']); ?></span>
                            </div>
                            
                            <!-- Inline Purchase Incentive Box -->
                            <div class="purchase-incentive-box">
                                <div class="incentive-content">
                                    <div class="incentive-icon">🎯</div>
                                    <div class="incentive-text">
                                        <h5>חזור ללמוד עכשיו!</h5>
                                        <p>חדש את הגישה שלך והמשך ללמוד מהמקום שבו הפסקת</p>
                                    </div>
                                </div>
                                
                                <?php if ($course['product_id']) : 
                                    $product = wc_get_product($course['product_id']);
                                    if ($product) : ?>
                                        <div class="incentive-actions">
                                            <div class="price-display">
                                                <span class="price"><?php echo $product->get_price_html(); ?></span>
                                            </div>
                                            <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="incentive-button">
                                                🛒 חדש מנוי עכשיו
                                            </a>
                                        </div>
                                    <?php else : ?>
                                        <div class="incentive-actions">
                                            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="incentive-button">
                                                🛒 עבור לחנות
                                            </a>
                                        </div>
                                    <?php endif;
                                else : ?>
                                    <div class="incentive-actions">
                                        <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="incentive-button">
                                            🛒 עבור לחנות
                                        </a>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="incentive-benefits">
                                    <ul>
                                        <li>✅ גישה מיידית לכל החומרים</li>
                                        <li>✅ מבחני תרגול ללא הגבלה</li>
                                        <li>✅ תמיכה טכנית מלאה</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($access_info['active_courses'])) : ?>
                <div class="access-notice active-notice">
                    <h4>✅ גישה פעילה</h4>
                    <?php foreach ($access_info['active_courses'] as $course) : ?>
                        <?php if (!in_array($course, $access_info['expiring_soon'])) : ?>
                            <div class="course-expiry-item active">
                                <strong><?php echo esc_html($course['title']); ?></strong>
                                <span class="expiry-text">תוקף עד <?php echo esc_html($course['expires_formatted']); ?></span>
                                <a href="<?php echo esc_url($course['url']); ?>" class="continue-button">המשך ללמוד</a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render dashboard HTML
     */
    public function render_dashboard($atts) {
        // Only show to logged in users
        if (!is_user_logged_in()) {
            return '<div class="user-dashboard-login-notice">יש להתחבר למערכת כדי לצפות בלוח הבקרה.</div>';
        }

        // Parse attributes with defaults
        $atts = shortcode_atts($this->defaults, $atts, 'user_dashboard');
        
        // Get vehicle type text
        $vehicle_text = $this->get_vehicle_type_text($atts['vehicle_type']);
        
        // Prepare welcome text
        $welcome_text = sprintf($atts['welcome_text'], $this->get_user_full_name());

        // Get course access information
        $access_info = $this->get_user_course_access();

        ob_start();
        ?>
        <div class="user-dashboard-container">
            <!-- Course Access Status - Full Width Section -->
            <?php echo $this->render_course_access_status($access_info); ?>
            
            <div class="dashboard-content">
                <!-- Left Column - User Panel -->
                <div class="dashboard-column user-panel">
                    <div class="user-greeting">
                        <h2><?php echo esc_html($welcome_text); ?></h2>
                        <div class="user-meta">
                            <div class="meta-item date">
                                <span class="meta-icon">📅</span>
                                <span class="meta-text"><?php echo esc_html($this->get_current_date()); ?></span>
                            </div>
                            <div class="meta-item track">
                                <span class="meta-icon">🎯</span>
                                <span class="meta-text"><?php echo esc_html($atts['track_name']); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="user-actions">
                        <a href="<?php echo esc_url($atts['account_url']); ?>" class="user-action-link edit-account">
                            <span class="link-icon">✏️</span>
                            <span class="link-text">ערוך חשבון (<?php echo esc_html($vehicle_text); ?>)</span>
                        </a>
                        <?php if ($atts['show_stats'] === 'true') : ?>
                            <?php 
                            // Check if current user is a teacher
                            $current_user = wp_get_current_user();
                            $is_teacher = false;
                            $teacher_roles = array('administrator', 'school_teacher', 'wdm_instructor', 'instructor', 'wdm_swd_instructor', 'swd_instructor');
                            
                            foreach ($teacher_roles as $role) {
                                if (in_array($role, $current_user->roles)) {
                                    $is_teacher = true;
                                    break;
                                }
                            }
                            
                            if ($is_teacher) {
                                // Teacher - redirect to teacher dashboard with their ID
                                $stats_url = 'https://test-li.ussl.co.il/teacher_dashboard/?teacher_id=' . $current_user->ID;
                            } else {
                                // Student - scroll to quiz progress details on same page
                                $stats_url = '#quiz_progress_details';
                            }
                            ?>
                        <a href="<?php echo esc_url($stats_url); ?>" class="user-action-link stats" <?php echo !$is_teacher ? 'onclick="document.getElementById(\"quiz_progress_details\").style.display=\"block\"; document.getElementById(\"quiz_progress_details\").scrollIntoView({behavior: \"smooth\"}); return false;"' : ''; ?>>
                            <span class="link-icon">📊</span>
                            <span class="link-text">סטטיסטיקות לימוד</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($atts['show_logout'] === 'true') : ?>
                        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="user-action-link logout">
                            <span class="link-icon">🚪</span>
                            <span class="link-text">התנתק</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Middle Column - Practice Tests -->
                <?php if ($atts['show_practice'] === 'true' || $atts['show_real_test'] === 'true' || $atts['show_teacher_quizzes'] === 'true') : ?>
                <div class="dashboard-column test-column">
                    <div class="column-header">
                        <h3>מבחנים כדוגמת מבחן התיאוריה</h3>
                    </div>
                    <div class="button-group">
                        <?php if ($atts['show_practice'] === 'true') : ?>
                        <a href="<?php echo esc_url(home_url('quizzes/מבחן-תרגול-להמחשה/')); ?>" class="dashboard-button practice-button">
                            <span class="button-text">מבחני תרגול</span>
                            <span class="button-icon">📝</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($atts['show_real_test'] === 'true') : ?>
                        <a href="<?php echo esc_url(home_url('/courses/פרטי/lessons/פרק-01-תורת-החינוך-התעברותי-פרק-מבוא/quizzes/מבחן-אמת-כמו-בתאוריה/')); ?>" class="dashboard-button real-test-button">
                            <span class="button-text">מבחני אמת – כמו בתיאוריה</span>
                            <span class="button-icon">📋</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($atts['show_teacher_quizzes'] === 'true') : ?>
                            <?php 
                            $teacher_id = $this->get_student_teacher_id();
                            if ($teacher_id) {
                                $teacher_quizzes = $this->get_teacher_quizzes($teacher_id, 1); // Get only the latest quiz
                                if (!empty($teacher_quizzes)) {
                                    $latest_quiz = $teacher_quizzes[0];
                                    $quiz_url = $latest_quiz->quiz_url;
                                } else {
                                    // Default URL if no quizzes found - you can change this
                                    $quiz_url = home_url('/quizzes/');
                                }
                            } else {
                                // Default URL if no teacher assigned - you can change this
                                $quiz_url = home_url('/quizzes/');
                            }
                            ?>
                            <a href="<?php echo esc_url($quiz_url); ?>" class="dashboard-button teacher-quiz-button">
                                <span class="button-text">מבחן מורה</span>
                                <span class="button-icon">🎓</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Right Column - Questions by Topic -->
                <?php if ($atts['show_study_materials'] === 'true' || $atts['show_topic_tests'] === 'true') : ?>
                <div class="dashboard-column questions-column">
                    <div class="column-header">
                        <h3>שאלות מהמאגר לפי נושאים</h3>
                    </div>
                    <div class="button-group">
                        <?php if ($atts['show_study_materials'] === 'true') : ?>
                        <a href="<?php echo esc_url($atts['study_materials_url']); ?>" class="dashboard-button study-materials-button">
                            <span class="button-text">חומר לימוד לפי נושאים</span>
                            <span class="button-icon">📚</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($atts['show_topic_tests'] === 'true') : ?>
                        <a href="<?php echo esc_url($atts['topic_tests_url']); ?>" class="dashboard-button topic-tests-button">
                            <span class="button-text">מבחנים לפי נושאים</span>
                            <span class="button-icon">📝</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="dashboard-footer">
                <p>בהצלחה בלימוד ובתרגול!</p>
            </div>
        </div>
        
        <style>
        /* Purchase Incentive Box Styles */
        .purchase-incentive-box {
            background: linear-gradient(135deg, #f8f9ff 0%, #e8f2ff 100%);
            border: 2px solid #4a90e2;
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.15);
            transition: all 0.3s ease;
        }
        
        .purchase-incentive-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.25);
        }
        
        .incentive-content {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            gap: 15px;
        }
        
        .incentive-icon {
            font-size: 2.5em;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .incentive-text h5 {
            color: #2c5aa0;
            font-size: 1.3em;
            margin: 0 0 8px 0;
            font-weight: bold;
        }
        
        .incentive-text p {
            color: #555;
            margin: 0;
            font-size: 0.95em;
        }
        
        .incentive-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            gap: 15px;
        }
        
        .price-display {
            background: #fff;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .price-display .price {
            font-size: 1.2em;
            font-weight: bold;
            color: #e74c3c;
        }
        
        .incentive-button {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white !important;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            font-size: 1.1em;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .incentive-button:hover {
            background: linear-gradient(135deg, #357abd 0%, #2c5aa0 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.4);
            text-decoration: none;
            color: white !important;
        }
        
        .incentive-benefits ul {
            list-style: none;
            padding: 0;
            margin: 0;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            padding: 15px;
        }
        
        .incentive-benefits li {
            padding: 5px 0;
            color: #2c5aa0;
            font-size: 0.9em;
            font-weight: 500;
        }
        
        .expired-course-header {
            margin-bottom: 10px;
        }
        
        .expired-course-header strong {
            display: block;
            color: #e74c3c;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        
        .expired-course-header .expiry-text {
            color: #888;
            font-size: 0.9em;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .incentive-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .incentive-button {
                text-align: center;
                justify-content: center;
            }
            
            .incentive-content {
                flex-direction: column;
                text-align: center;
            }
        }
        </style>
        
        <?php
        return ob_get_clean();
    }
}

// Initialize the shortcode
new User_Dashboard_Shortcode();
