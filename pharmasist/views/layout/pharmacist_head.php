<?php
/**
 * MediVault - Pharmacist module
 * File    : views/layout/pharmacist_head.php
 * Title   : HTML head (document start)
 *
 * FRONTEND (HTML)
 * Opens <html>, <head> and <body>. The CSS that used to be inline now lives in assets/css/.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediVault - Pharmacist Dashboard</title>
    <link rel="stylesheet" href="../common/common.css">
    <!-- Main dashboard stylesheet (was the inline <style> block of the old single file) -->
    <link rel="stylesheet" href="assets/css/pharmacist_dashboard.css">
    <!-- Prescription viewer overlay stylesheet -->
    <link rel="stylesheet" href="assets/css/pharmacist_rx_viewer.css">
</head>
<body>
