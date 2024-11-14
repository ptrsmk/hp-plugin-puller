<?php
/*
Plugin Name: HolyPixels Plugin Puller
Plugin URI: https://github.com/ptrsmk/hp-plugin-puller
Description: Download Plugin Files (Individually) From Github Repo
Version: 1.0
Author: HolyPixels (ptrsmk)
Author URI: https://github.com/ptrsmk/
*/

if (!defined('YOURLS_ABSPATH')) die();

yourls_add_action('plugins_loaded', 'holyp_download_plugin_settings');
function holyp_download_plugin_settings()
{
    yourls_register_plugin_page('hp_puller_settings', 'Plugin Puller', 'holyp_plugin_puller_page');
}

function holyp_plugin_puller_page()
{
    $msg = '';

    if (isset($_POST['github_url'])) {
        yourls_verify_nonce('download_plugin_settings');
        list($is, $txt) = holyp_pull_plugin();
        $info = $is ? ['txt' => 'success', 'color' => 'green'] : ['text' => 'fail', 'color' => 'red'];
        $msg = "<p style='color: {$info['color']}'>download {$info['txt']}: {$txt}</p>";
    }

    $nonce = yourls_create_nonce('download_plugin_settings');
    echo <<<HTML
        <main>
            <h2>Pull a Plugin</h2>
            <p><a href="https://github.com/YOURLS/awesome-yourls" target="_blank">plugin list</a></p>
            {$msg}
            <form method="post">
            <input type="hidden" name="nonce" value="$nonce" />
            <p>
                <label>GitHub Repo Base URL</label>
                <input type="text" name="github_url" value="" required />
                <hint>supports github, like: <code>https://github.com/ptrsmk/hp-plugin-puller</code></hint>
            </p>
            <p>
            <p>
                <label>Folder Name</label>
                <input type="text" name="folder_name" value="" />
                <hint>optional, destination folder name. If empty repo name in url will be used.</hint>
            </p>
            <p><input type="submit" value="Download" class="button" /></p>
            </form>
        </main>
HTML;
}

function holyp_pull_plugin()
{
    $repoUrl = $_POST['github_url'];
    $destinationFolder = $_POST['folder_name'];

    return downloadGitHubRepoFiles($repoUrl, $destinationFolder = null, $token = null);

    // parse url
    if (strpos($url, 'https://github.com/') === 0) {
        list($downloadUrl, $unzipFolderName) = kriss_parse_github_url($url, $branch);
    } else {
        return [false, 'url not support'];
    }

    $downloadName = $name ?: basename($url) . '.zip';
    $filepath = __DIR__ . '/../' . $downloadName;
    $unzipPath = __DIR__ . '/../';
    $unzipFolderName = __DIR__ . '/../' . $unzipFolderName . '/';

    // download file
    if (file_exists($filepath)) {
        return [false, 'file ' . $filepath . ' existed'];
    }
    $content = file_get_contents($downloadUrl);
    file_put_contents($filepath, $content);

    // unzip
    $zip = new ZipArchive();
    $unzipOk = false;
    if ($zip->open($filepath) === true) {
        $zip->extractTo($unzipPath);
        $zip->close();
        $unzipOk = true;
    }

    // auto detect plugin root
    $pluginPath = kriss_auto_detect_plugin_root($unzipFolderName, 'plugin.php');
    if ($pluginPath === false) {
        unlink($filepath);
        return [false, 'no plugin.php find in zip'];
    }
    if ($unzipFolderName . 'plugin.php' !== $pluginPath) {
        copy($pluginPath, $unzipFolderName . 'plugin.php');
    }

    // delete file
    if (isset($_POST['delete_after_unzip']) && $_POST['delete_after_unzip']) {
        unlink($filepath);
    }

    if (!$unzipOk) {
        return [false, 'unzip failed'];
    }

    return [true, $downloadName];
}

function kriss_parse_github_url($url, $branch)
{
    $downloadUrl = "$url/archive/refs/heads/{$branch}.zip";
    $unzipFolderName = basename($url) . '-' . $branch;

    return [$downloadUrl, $unzipFolderName];
}

function kriss_auto_detect_plugin_root($path, $pluginName)
{
    $path = rtrim($path, '/');
    if (file_exists($path . '/'. $pluginName)) {
        return $path . '/'. $pluginName;
    }
    foreach (glob($path . '/*', GLOB_ONLYDIR) as $dir) {
        return kriss_auto_detect_plugin_root($dir, $pluginName);
    }
    return false;
}


// Function to take a GitHub repo URL, download all files (including from subdirectories), and save them in a local folder
function downloadGitHubRepoFiles($repoUrl, $destinationFolder = null, $token = null) {
    // Extract repo owner and name from the URL
    $repoData = parseGitHubUrl($repoUrl);
    if (!$repoData) {
        return [false, "Invalid GitHub URL.\n"];
    }

    $repoOwner = $repoData['owner'];
    $repoName = $repoData['repo'];

    // Default destination folder is the repo name if not provided
    $destinationFolder = $destinationFolder ?? $repoName;

    // Get the parent directory of the current working directory
    $parentDir = dirname(getcwd());
    $fullDestinationPath = $parentDir . '/' . $destinationFolder; // Set the destination path in the parent directory

    // Fetch the repository contents from the GitHub API
    $apiUrl = "https://api.github.com/repos/$repoOwner/$repoName/contents";
    $files = getRepoContents($apiUrl, $token);

    if (!$files) {
        return [false, "Error fetching files from GitHub.\n"];
    }

    // Check if 'plugin.php' exists in the list of files
    $pluginFileFound = false;
    foreach ($files as $file) {
        if ($file['name'] === 'plugin.php') {
            $pluginFileFound = true;
            break;
        }
    }

    if (!$pluginFileFound) {
        return [false, "'plugin.php' is not found in the GitHub repository. Aborting pull of files.\n"];
    }

    // Create the destination directory if it doesn't already exist
    if (!is_dir($fullDestinationPath)) {
        mkdir($fullDestinationPath, 0755, true);
    }

    // Proceed with downloading the files
    fetchAndDownloadFiles($apiUrl, $fullDestinationPath, $token);

    return [true, "All files from $repoName downloaded into the '$fullDestinationPath' folder.\n"];
}

// Function to parse the GitHub repository URL and extract the owner and repo name
function parseGitHubUrl($url) {
    $pattern = '/https:\/\/github\.com\/([^\/]+)\/([^\/]+)/';
    if (preg_match($pattern, $url, $matches)) {
        return ['owner' => $matches[1], 'repo' => $matches[2]];
    }
    return [false, "Failed to parse the given URL."];
}

// Function to recursively fetch the contents of a GitHub repository or directory
function fetchAndDownloadFiles($apiUrl, $destinationFolder, $token = null) {
    $files = getRepoContents($apiUrl, $token);
    if (!$files) {
        return [false, "Error fetching files from GitHub.\n"];
    }

    // Loop through the files and handle directories and files separately
    foreach ($files as $file) {
        $localPath = $destinationFolder . '/' . $file['name'];

        // If it's a directory, call fetchAndDownloadFiles recursively
        if ($file['type'] === 'dir') {
            // echo "Entering directory: $localPath\n";÷
            if (!is_dir($localPath)) {
                mkdir($localPath, 0755, true);
            }
            // Recurse into the directory
            fetchAndDownloadFiles($file['url'], $localPath, $token);
        } else {
            // If it's a file, download it
            // echo "Downloading file: $localPath\n";
            downloadFile($file['download_url'], $localPath);
        }
    }
}

// Function to fetch the contents of a GitHub repository using the GitHub API
function getRepoContents($apiUrl, $token = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP GitHub Downloader');

    // Add token for higher rate limits
    if ($token) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: token $token"
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Check for successful response
    if ($httpCode !== 200) {
        return [false, "Error fetching repository data: HTTP $httpCode\n"];
    }

    return json_decode($response, true);
}

// Function to download a file from a URL and save it locally
function downloadFile($url, $localFilePath) {
    $ch = curl_init($url);
    $fp = fopen($localFilePath, 'wb');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    fclose($fp);

    if ($httpCode !== 200) {
        echo "Error downloading '$url': HTTP $httpCode\n";
    }
    // else {
    //     echo "Downloaded '$localFilePath'\n";
    // }
}

// Example usage
// $repoUrl = 'https://github.com/MatthewC/yourls-2fa-support'; // Replace with the desired GitHub repo URL
// $token = 'your_github_token_here'; // Optional: GitHub token for higher rate limits
// downloadGitHubRepoFiles($repoUrl, null, $token);
?>
