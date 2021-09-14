<?php
require_once('vendor/autoload.php');

use splitbrain\phpcli\CLI;
use splitbrain\PHPArchive\Zip;

class GPM extends CLI {
  protected function setup($options) {
    $options->setHelp('The Git Package Manager');

    $options->registerCommand('install', 'Install a package');
    $options->registerArgument('package', 'Package name', false, 'install');
    $options->registerArgument('version', 'Package version', false, 'install');
    $options->registerOption('save', 'Adds the package to the gpm.json file.', 's', false, 'install');
    $options->registerOption('path', 'Path to the gpm.json file.', 'p', true, 'install');
    $options->registerOption('install-path', 'Path to install packages.', 'i', true, 'install');

    $options->registerCommand('uninstall', 'Uninstalls a package');
    $options->registerArgument('package', 'Package name', true, 'uninstall');
    $options->registerOption('save', 'Removes the package from the gpm.json file.', 's', false, 'uninstall');
    $options->registerOption('path', 'Path to the gpm.json file.', 'p', true, 'uninstall');
    $options->registerOption('install-path', 'Path that the package is installed in.', 'i', true, 'uninstall');

    $options->registerCommand('init', 'Creates a gpm.json file if one doesn\'t already exist.');
    $options->registerOption('path', 'Path to create the gpm.json file.', 'p', true, 'init');

    $options->registerCommand('versions', 'Lists available versions for a package.');
    $options->registerArgument('package', 'Package name', true, 'versions');
  }

  protected function main($options) {
    $cmd = $options->getCmd();
    $args = $options->getArgs();
    $path = $options->getOpt('path');
    $install_path = $options->getOpt('install-path');
    $save = $options->getOpt('save');

    switch($cmd) {
      case 'install':
        if (count($args)) {
          $package = $args[0];
          $version = isset($args[1]) ? $args[1] : null;

          $this->install_package($package, $version, $path, $install_path, $save);
        } else {
          $this->install($path, $install_path, $save);
        }

        break;
      case 'uninstall':
        $this->uninstall_package($args[0], $path, $install_path, $save);

        break;
      case 'init':
        $this->create_json_file($path);

        break;
      case 'versions':
        if ($versions = $this->get_available_package_versions($args[0])) {
          $versions = implode('\'</>, \'<green>', $versions);
          $this->write("[ <green>'$versions'</green> ]");
        } else {
          $this->write("<lightred>Error</>: Could not find the package <green>$args[0]</>.\n");
        }

        break;
      default:
        echo $options->help();
    }
  }

  protected function write($message, $channel = STDOUT) {
    $used_colors = array('reset');

    $message = preg_replace_callback('/\<(.[^\>]*?)\>/', function ($matches) use (&$used_colors) {
      $color = $matches[1];

      if (substr($color, 0, 1) === '/') {
        array_pop($used_colors);
        $color = end($used_colors);
      } else {
        $used_colors[] = $color;
      }

      return $this->colors->getColorCode($color);
    }, $message);

    fwrite($channel, $message . "\n");
  }

  protected function clean_directory($directory, $delete = false, $only_delete_if_already_empty = false) {
    if (file_exists($directory)) {
      $empty = true;

      foreach(glob("{$directory}/{*,.[!.]*,..?*}", GLOB_BRACE) as $file) {
        if ($only_delete_if_empty) {
          $empty = false;
          break;
        }

        if (is_dir($file)) {
          $this->clean_directory($file, true);
        } else {
          unlink($file);
        }
      }

      if ($delete && $empty) {
        rmdir($directory);
      }
    } elseif (!$delete) {
      mkdir($directory);
    }
  }

  public function create_json_file($path) {
    $path = $path ? rtrim($path, '/') : '.';
    $file = "$path/gpm.json";

    if (file_exists($file)) {
      $this->write("<lightred>Error</>: <yellow>$file</> already exists.");
      return false;
    }

    $json = json_encode(array('dependencies' => (object) null), JSON_PRETTY_PRINT);
    $results = file_put_contents($file, stripslashes($json));

    if ($results !== false) {
      $this->write("<yellow>$file</> was created.");
      return true;
    }

    return false;
  }

  public function load_dependencies($path) {
    $path = $path ? rtrim($path, '/') : '.';
    $file = "$path/gpm.json";

    $this->write("Loading <yellow>$file</yellow>...");

    if (
      !($json = @file_get_contents("$path/gpm.json")) ||
      !($info = @json_decode($json, true))
    ) {
      $this->write("<lightred>Error</>: Could not read GPM file (<yellow>$file</>).");
      return false;
    }

    if (!isset($info['dependencies']) || !is_array($info['dependencies'])) {
      $this->write("<lightred>Error</>: Invalid GPM file (<yellow>$file</>).");
      return false;
    }

    return $info;
  }

  public function edit_dependencies($package, $version = false, $path = null) {
    if (!($info = $this->load_dependencies($path))) return false;

    if ($version) {
      $info['dependencies'][$package] = $version;
    } elseif (isset($info['dependencies'][$package])) {
      unset($info['dependencies'][$package]);
    }

    $file = "$path/gpm.json";
    $results = file_put_contents($file, stripslashes(json_encode($info, JSON_PRETTY_PRINT)));

    if ($results !== false) {
      $this->write("<yellow>$file</> was updated.");
      return true;
    }

    return false;
  }

  public function get_available_package_versions($package, $count = null) {
    $api_path = "https://api.github.com/repos/$package";
    $tags = array();
    $commits = array();
    $tagged_commits = array();

    $context = stream_context_create(array(
      'http' => array(
        'user_agent' => 'gpm'
      )
    ));

    if ($tags_download = @file_get_contents("$api_path/tags", false, $context)) {
      $tag_info = json_decode($tags_download, true);

      if (!empty($tag_info)) {
        $tags = array_map(function ($tag) use(&$tagged_commits) {
          $tagged_commits[] = '#' . substr($tag['commit']['sha'], 0, 7);
          return preg_replace('/^v(\d.*)/i', '$1', $tag['name']);
        }, $tag_info);

        usort($tags, function ($a, $b) {
          return version_compare($b, $a);
        });

        if ($count) {
          $tags = array_slice($tags, 0, $count);
        }
      }
    }

    $tags_count = count($tags);
    if ($count && $tags_count === $count) return $tags;

    if ($commits_download = @file_get_contents("$api_path/commits", false, $context)) {
      $commits_info = json_decode($commits_download, true);

      if (!empty($commits_info)) {
        usort($commits_info, function($a, $b) {
          return (strtotime($a['commit']['author']['date']) < strtotime($b['commit']['author']['date']));
        });

        $commits = array_filter(array_map(function ($commit) {
          return '#' . substr($commit['sha'], 0, 7);
        }, $commits_info), function ($commit) use ($tagged_commits) {
          return !in_array($commit, $tagged_commits);
        });

        if ($count) {
          $commits = array_slice($commits, 0, ($count - $tags_count));
        }
      }
    }

    if ($tags_count || count($commits)) {
      return array_merge($tags, $commits);
    }

    return false;
  }

  public function install($path = null, $install_path = null) {
    $path = $path ? rtrim($path, '/') : '.';
    $install_path = $install_path ? rtrim($install_path, '/') : "$path/gpm_modules";
    $tmp_path = "$install_path/.tmp";

    if (!($info = $this->load_dependencies($path))) return false;

    $dependency_count = count($info['dependencies']);
    $this->write("$dependency_count dependencies found.");

    if (isset($info['dependencies'])) {
      foreach($info['dependencies'] as $package => $package_version) {
        $this->install_package($package, $package_version, $path, $install_path);
      }

      $this->clean_directory($tmp_path, true);

      $this->write('Done!');

      return true;
    }

    return false;
  }

  public function install_package($package, $version = null, $path = null, $install_path = null, $save = false) {
    $path = $path ? rtrim($path, '/') : '.';
    $install_path = $install_path ? rtrim($install_path, '/') : "$path/gpm_modules";
    $tmp_path = "$install_path/.tmp";

    $package_parts = explode('/', $package);
    $package_author = $package_parts[0];
    $package_name = $package_parts[1];

    $tmp_author_path = "$tmp_path/$package_author";
    $tmp_package_path = "$tmp_author_path/$package_name.zip";

    $author_path = "$install_path/$package_author";
    $package_path = "$author_path/$package_name";

    $download_url = "https://github.com/$package/archive";
    $alt_download_url = null;

    if (!$version) {
      $versions = $this->get_available_package_versions($package, 1);

      if ($versions) {
        $version = $versions[0];
      } else {
        $this->write("<lightred>Error</>: Could not find the package <green>$package</>.");
        return false;
      }
    }

    if (substr($version, 0, 4) === 'http') {
      $download_url = $version;
      $tmp_package_path = "$tmp_author_path/" . basename($version);
    } elseif (substr($version, 0, 1) === '#') {
      $download_url .= '/' . substr($version, 1) . '.zip';
    } elseif (substr($version, 0, 4) === 'dev-') {
      $download_url .= '/refs/heads/' . substr($version, 4) . '.zip';
    } else {
      $alt_download_url = "$download_url/refs/tags/v$version.zip";
      $download_url .= "/refs/tags/$version.zip";
    }

    $context = stream_context_create(array(
      'http' => array(
        'user_agent' => 'gpm'
      )
    ));

    $this->write(" - <purple>Downloading</> <green>$package</> (<brown>$download_url</>)...");
    $download_contents = @fopen($download_url, 'r', false, $context);

    if (!$download_contents && $alt_download_url) {
      $download_url = $alt_download_url;
      $this->write(" - <purple>Downloading</> <green>$package</> (<brown>$download_url</>)...");
      $download_contents = @fopen($download_url, 'r', false, $context);
    }

    if (!$download_contents) {
      $versions = $this->get_available_package_versions($package, 5);

      if ($versions) {
        $versions = implode(', ', $versions);
        $this->write("<lightred>Error</>: Unable to find version <brown>$version</> of package <green>$package</>. Found: $versions...\n");
      } else {
        $this->write("<lightred>Error</>: Could not find the package <green>$package</>.\n");
      }

      return false;
    }

    if (!file_exists($tmp_path)) mkdir($tmp_path);
    if (!file_exists($tmp_author_path)) mkdir($tmp_author_path);
    if (!file_exists($author_path)) mkdir($author_path);
    if (file_exists($tmp_package_path)) unlink($tmp_package_path);

    file_put_contents($tmp_package_path, $download_contents);
    fclose($download_contents);

    $this->write(" - <cyan>Extracting</> <green>$tmp_package_path</>...");

    $extension = pathinfo($tmp_package_path, PATHINFO_EXTENSION);

    if ($extension === 'zip') {
      $this->clean_directory($package_path);

      $zip = new Zip();
      $zip->open($tmp_package_path);
      $zip->extract($package_path, 1, '/\.github/');

      unlink($tmp_package_path);
    } else {
      $package_path = "$author_path/$package_name" . ($extension ? ".$extension" : '');

      if (file_exists($package_path)) unlink($package_path);

      rename($tmp_package_path, $package_path);
    }

    if ($save) {
      $this->edit_dependencies($package, $version, $path);
    }

    return array(
      'name'          => $package_name,
      'version'       => $version,
      'author'        => $package_author,
      'download_url'  => $download_url,
      'path'          => $package_path
    );
  }

  public function uninstall_package($package, $path = null, $install_path = null, $save = false) {
    $path = $path ? rtrim($path, '/') : '.';
    $install_path = $install_path ? rtrim($install_path, '/') : "$path/gpm_modules";

    $package_parts = explode('/', $package);
    $package_author = $package_parts[0];
    $package_name = $package_parts[1];

    $author_path = "$install_path/$package_author";
    $package_path = "$author_path/$package_name";

    if (file_exists($package_path)) {
      $this->clean_directory($package_path, true);
      $this->clean_directory($author_path, true, true);
    }

    if ($save) {
      $this->edit_dependencies($package, null, $path);
    }

    return true;
  }
}

$cli = new GPM();
$cli->run();
?>