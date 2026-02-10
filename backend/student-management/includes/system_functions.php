<?php
/**
 * System Functions for Colegio de Amore
 * Handles system settings, preferences, translations, etc.
 */

if (!function_exists('getSystemSetting')) {
    /**
     * Get system setting value
     */
    function getSystemSetting($key, $default = null) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($setting) {
                $value = $setting['setting_value'];
                // Convert based on type
                switch ($setting['setting_type']) {
                    case 'boolean':
                        return $value === '1' || $value === 'true';
                    case 'integer':
                        return (int)$value;
                    case 'json':
                        return json_decode($value, true);
                    default:
                        return $value;
                }
            }
        } catch (PDOException $e) {
            error_log("Error getting system setting: " . $e->getMessage());
        }
        
        return $default;
    }
}

if (!function_exists('setSystemSetting')) {
    /**
     * Set system setting value
     */
    function setSystemSetting($key, $value, $type = 'string', $description = null) {
        global $pdo;
        
        try {
            // Convert value based on type
            switch ($type) {
                case 'boolean':
                    $value = $value ? '1' : '0';
                    break;
                case 'integer':
                    $value = (string)(int)$value;
                    break;
                case 'json':
                    $value = json_encode($value);
                    break;
                default:
                    $value = (string)$value;
            }
            
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([$key, $value, $type, $description, $userId]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Error setting system setting: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getUserPreference')) {
    /**
     * Get user preference
     */
    function getUserPreference($userId, $key, $default = null) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?");
            $stmt->execute([$userId, $key]);
            $pref = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $pref ? $pref['preference_value'] : $default;
        } catch (PDOException $e) {
            error_log("Error getting user preference: " . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('setUserPreference')) {
    /**
     * Set user preference
     */
    function setUserPreference($userId, $key, $value) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_preferences (user_id, preference_key, preference_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()
            ");
            $stmt->execute([$userId, $key, $value]);
            return true;
        } catch (PDOException $e) {
            error_log("Error setting user preference: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getTranslation')) {
    /**
     * Get translation for current language
     */
    function getTranslation($key, $languageCode = null, $default = null) {
        global $pdo;
        
        if ($languageCode === null) {
            $languageCode = getCurrentLanguage();
        }
        
        try {
            $stmt = $pdo->prepare("SELECT translation_value FROM translations WHERE language_code = ? AND translation_key = ?");
            $stmt->execute([$languageCode, $key]);
            $translation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $translation ? $translation['translation_value'] : ($default !== null ? $default : $key);
        } catch (PDOException $e) {
            error_log("Error getting translation: " . $e->getMessage());
            return $default !== null ? $default : $key;
        }
    }
}

if (!function_exists('getCurrentLanguage')) {
    /**
     * Get current user's language preference
     */
    function getCurrentLanguage() {
        if (isset($_SESSION['user_id'])) {
            $lang = getUserPreference($_SESSION['user_id'], 'language');
            if ($lang) {
                return $lang;
            }
        }
        
        // Check cookie
        if (isset($_COOKIE['language'])) {
            return $_COOKIE['language'];
        }
        
        // Return system default
        return getSystemSetting('default_language', 'en');
    }
}


if (!function_exists('checkCoursePrerequisites')) {
    /**
     * Check if student meets course prerequisites
     */
    function checkCoursePrerequisites($pdo, $studentId, $subjectId) {
        try {
            // Get subject prerequisites
            $stmt = $pdo->prepare("SELECT prerequisites FROM subjects WHERE id = ?");
            $stmt->execute([$subjectId]);
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$subject || empty($subject['prerequisites'])) {
                return ['met' => true, 'message' => 'No prerequisites required'];
            }
            
            // Parse prerequisites (assuming comma-separated subject IDs or codes)
            $prerequisiteIds = array_filter(array_map('trim', explode(',', $subject['prerequisites'])));
            
            if (empty($prerequisiteIds)) {
                return ['met' => true, 'message' => 'No prerequisites required'];
            }
            
            // Check if student has completed prerequisites
            // Assuming prerequisites are stored as subject IDs
            $placeholders = implode(',', array_fill(0, count($prerequisiteIds), '?'));
            
            $stmt = $pdo->prepare("
                SELECT s.id, s.name, s.code,
                       MAX(g.grade) as highest_grade,
                       CASE WHEN MAX(g.grade) >= 75 THEN 1 ELSE 0 END as completed
                FROM subjects s
                LEFT JOIN grades g ON s.id = g.subject_id AND g.student_id = ?
                WHERE s.id IN ($placeholders)
                GROUP BY s.id, s.name, s.code
            ");
            
            $params = array_merge([$studentId], $prerequisiteIds);
            $stmt->execute($params);
            $prerequisites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $missing = [];
            $completed = [];
            
            foreach ($prerequisites as $prereq) {
                if ($prereq['completed']) {
                    $completed[] = $prereq;
                } else {
                    $missing[] = $prereq;
                }
            }
            
            if (empty($missing)) {
                return [
                    'met' => true,
                    'message' => 'All prerequisites met',
                    'completed' => $completed
                ];
            }
            
            return [
                'met' => false,
                'message' => 'Prerequisites not met',
                'missing' => $missing,
                'completed' => $completed
            ];
        } catch (PDOException $e) {
            error_log("Error checking prerequisites: " . $e->getMessage());
            return ['met' => true, 'message' => 'Error checking prerequisites', 'error' => $e->getMessage()];
        }
    }
}

