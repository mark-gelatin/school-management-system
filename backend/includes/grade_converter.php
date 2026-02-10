<?php
/**
 * Philippine College Grading System Converter
 * Converts percentage grades to Philippine grade scale (1.0-5.0)
 */

/**
 * Convert percentage to Philippine grade scale
 * @param float $percentage The percentage grade (0-100)
 * @return float Philippine grade (1.0-5.0)
 */
function percentageToPhilippineGrade($percentage) {
    if ($percentage >= 97) return 1.0;
    if ($percentage >= 94) return 1.25;
    if ($percentage >= 91) return 1.5;
    if ($percentage >= 88) return 1.75;
    if ($percentage >= 85) return 2.0;
    if ($percentage >= 82) return 2.25;
    if ($percentage >= 79) return 2.5;
    if ($percentage >= 76) return 2.75;
    if ($percentage >= 75) return 3.0;
    if ($percentage >= 70) return 4.0;
    return 5.0; // Failed (Below 70%)
}

/**
 * Convert raw score to Philippine grade
 * @param float $score The raw score
 * @param float $maxPoints Maximum possible points
 * @return float Philippine grade (1.0-5.0)
 */
function scoreToPhilippineGrade($score, $maxPoints = 100) {
    if ($maxPoints <= 0) return 5.0;
    $percentage = ($score / $maxPoints) * 100;
    return percentageToPhilippineGrade($percentage);
}

/**
 * Get grade description based on Philippine grade
 * @param float $phGrade Philippine grade (1.0-5.0)
 * @return string Grade description
 */
function getPhilippineGradeDescription($phGrade) {
    if ($phGrade == 1.0) return 'Excellent';
    if ($phGrade == 1.25) return 'Very Good';
    if ($phGrade == 1.5) return 'Good';
    if ($phGrade == 1.75) return 'Satisfactory';
    if ($phGrade == 2.0) return 'Fair';
    if ($phGrade == 2.25) return 'Pass';
    if ($phGrade == 2.5) return 'Conditional Pass';
    if ($phGrade == 2.75) return 'Conditional Pass';
    if ($phGrade == 3.0) return 'Conditional Pass';
    if ($phGrade == 4.0) return 'Conditional Pass';
    if ($phGrade == 5.0) return 'Failed';
    return 'Invalid';
}

/**
 * Get badge class for Philippine grade
 * @param float $phGrade Philippine grade (1.0-5.0)
 * @return string CSS class name
 */
function getPhilippineGradeBadgeClass($phGrade) {
    if ($phGrade <= 1.5) return 'high';      // Excellent to Good
    if ($phGrade <= 2.5) return 'medium';    // Satisfactory to Conditional Pass
    if ($phGrade <= 3.0) return 'medium';    // Conditional Pass
    if ($phGrade <= 4.0) return 'low';       // Conditional Pass (low)
    return 'low';                            // Failed
}

/**
 * Get color for Philippine grade
 * @param float $phGrade Philippine grade (1.0-5.0)
 * @return string Color name
 */
function getPhilippineGradeColor($phGrade) {
    if ($phGrade <= 1.5) return 'green';
    if ($phGrade <= 2.5) return 'blue';
    if ($phGrade <= 3.0) return 'orange';
    if ($phGrade <= 4.0) return 'yellow';
    return 'red';
}

/**
 * Format Philippine grade for display
 * @param float $phGrade Philippine grade (1.0-5.0)
 * @return string Formatted grade string
 */
function formatPhilippineGrade($phGrade) {
    return number_format($phGrade, 2);
}

/**
 * Convert Philippine grade back to percentage (approximate)
 * @param float $phGrade Philippine grade (1.0-5.0)
 * @return float Approximate percentage
 */
function philippineGradeToPercentage($phGrade) {
    if ($phGrade == 1.0) return 98.5;
    if ($phGrade == 1.25) return 95.0;
    if ($phGrade == 1.5) return 92.0;
    if ($phGrade == 1.75) return 89.0;
    if ($phGrade == 2.0) return 86.0;
    if ($phGrade == 2.25) return 83.0;
    if ($phGrade == 2.5) return 80.0;
    if ($phGrade == 2.75) return 77.0;
    if ($phGrade == 3.0) return 75.0;
    if ($phGrade == 4.0) return 72.0;
    if ($phGrade == 5.0) return 65.0;
    return 0;
}

