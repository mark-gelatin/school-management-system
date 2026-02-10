<?php
/**
 * Backend Database Connection
 * Uses centralized configuration from config/database.php
 * This file maintains backward compatibility while using the new config structure
 */

// Use centralized database configuration
require_once __DIR__ . '/../../config/database.php';

// Return the PDO connection from the config
return getDatabaseConnection();


