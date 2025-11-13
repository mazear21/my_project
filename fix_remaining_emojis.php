<?php
/**
 * Script to remove remaining emojis from index.php
 * Run this once and then delete it
 */

$file = 'index.php';
$content = file_get_contents($file);

// Define all emoji replacements
$replacements = [
    // Chart loading emoji
    '📊 Loading chart data' => '<i class="fas fa-spinner fa-spin"></i> Loading chart data',
    
    // Export CSV buttons - Note: These are in translate attributes so icons are in HTML
    'onclick="exportData(\'csv\')" data-translate="export_csv">📊 Export CSV' => 'onclick="exportData(\'csv\')" data-translate="export_csv"><i class="fas fa-file-csv"></i> Export CSV',
    
    // Medal emojis in chart formatters
    '🥇' => '#1',
    '🥈' => '#2',
    '🥉' => '#3',
    '🏆' => '#',
    
    // Clipboard copy emoji
    '📋 Copy' => '<i class="fas fa-copy"></i> Copy',
];

// Apply replacements
foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced '{$search}' - {$count} occurrence(s)<br>";
    }
}

// Save the file
file_put_contents($file, $content);

echo "<br><strong>✓ All remaining emojis removed from index.php!</strong><br><br>";
echo "<strong>IMPORTANT:</strong> Delete this file (fix_remaining_emojis.php) after running it once.<br>";
?>
