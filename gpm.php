<?php
use splitbrain\phpcli\CLI;
use splitbrain\PHPArchive\Zip;

class GPM extends CLI {
  protected function setup($options) {
    $options->setHelp('A PHP Command Line tool that makes it easy to download dependencies from GitHub.');

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
          $versions = implode('\'</green>, \'<green>', $versions);
          $this->print("[ <green>'$versions'</green> ]");
        } else {
          $this->print("<lightred>Error</lightred>: Could not find the package <green>$args[0]</green>.\n", STDERR);
        }

        break;
      default:
        echo $options->help();
    }
  }

  protected function print($text, $channel = STDOUT) {
    $active_colors = array();

    $text = preg_replace_callback('/\<(.[^\>]*?)\>/', function ($matches) use (&$active_colors) {
      $new_color = $matches[1];
      $colors = array();

      if (substr($new_color, 0, 1) === '/') {
        array_pop($active_colors);
        $colors[] = 'reset';
      } else {
        $active_colors[] = $new_color;
      }

      $colors = array_merge($colors, $active_colors);

      return implode('', array_map(function ($color) {
        return $this->colors->getColorCode($color);
      }, $colors));
    }, $text);

    fwrite($channel, $text . "\n");

    if (end($active_colors) !== 'reset') {
      $this->colors->reset($channel);
    }
  }

  protected function create_directory($directory) {
    $directories = array_values(array_filter(explode('/', $directory)));

    if (substr($directory, 0, 1) === '/') $directories[0] = '/' . $directories[0];

    foreach($directories as $i => $dir) {
      $path = implode('/', array_slice($directories, 0, ($i + 1)));

      if (!file_exists($path)) {
        if (!@mkdir($path)) {
          $this->print("<lightred>Error</lightred>: An error occured while attempting to create <yellow>$path</yellow> directory.", STDERR);
        }
      }
    }
  }

  protected function clean_directory($directory, $delete = false, $only_delete_if_already_empty = false) {
    if (file_exists($directory)) {
      $empty = true;

      foreach(glob("{$directory}/{*,.[!.]*,..?*}", GLOB_BRACE) as $file) {
        if ($only_delete_if_already_empty) {
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
    if (!$path) $path = '.';

    if (file_exists($path) && is_dir($path)) {
      $file = rtrim($path, '/') . '/gpm.json';
    } else {
      $file = $path;
    }

    if (file_exists($file)) {
      $this->print("<lightred>Error</lightred>: <yellow>$file</yellow> already exists.", STDERR);
      return false;
    }

    $json = json_encode(array('dependencies' => (object) null), JSON_PRETTY_PRINT);
    $results = file_put_contents($file, stripslashes($json));

    if ($results !== false) {
      $this->print("<yellow>$file</yellow> was created.");
      return true;
    }

    return false;
  }

  public function get_json_file_location($path) {
    if (!$path) $path = '.';

    if (file_exists($path) && is_dir($path)) {
      $path = rtrim($path, '/');
    } else {
      $json_file = $path;
      $path = dirname($path);
    }

    if ($json_file) {
      if (!file_exists($json_file)) {
        $this->print("<lightred>Error</lightred>: Could not find <yellow>$json_file</yellow>.", STDERR);
        return false;
      }
    } elseif (file_exists("$path/gpm.json")) {
      $json_file = "$path/gpm.json";
    } elseif (file_exists("$path/fec.json")) {
      $json_file = "$path/fec.json";
    } else {
      $this->print("<lightred>Error</lightred>: Could not find <yellow>$path/gpm.json</yellow> or <yellow>$path/fec.json</yellow>.", STDERR);
      return false;
    }

    return $json_file;
  }

  public function load_dependencies($json_file) {
    $json = @file_get_contents($json_file);

    if (!($json = @json_decode($json, true))) {
      $this->print("<lightred>Error</lightred>: An error occured while decoding JSON data (<yellow>$json_file</yellow>).", STDERR);
      return false;
    }

    if (!isset($json['dependencies']) || !is_array($json['dependencies'])) {
      $this->print("<lightred>Error</lightred>: JSON data does not contain a dependencies item (<yellow>$json_file</yellow>).", STDERR);
      return false;
    }

    return $json;
  }

  public function edit_dependencies($package, $version = false, $path = null) {
    if (!($json_file = $this->get_json_file_location($path))) return false;
    if (!($info = $this->load_dependencies($json_file))) return false;

    if ($version) {
      $info['dependencies'][$package] = $version;
    } elseif (isset($info['dependencies'][$package])) {
      unset($info['dependencies'][$package]);
    }

    $json_file = $this->get_json_file_location($path);
    $results = file_put_contents($json_file, stripslashes(json_encode($info, JSON_PRETTY_PRINT)));

    if ($results !== false) {
      $this->print("<yellow>$json_file</yellow> was updated.");
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
    if (!($json_file = $this->get_json_file_location($path))) return false;
    if (!($info = $this->load_dependencies($json_file))) return false;

    $dependency_count = count($info['dependencies']);
    $this->print("$dependency_count dependencies found.");

    if (isset($info['dependencies'])) {
      foreach($info['dependencies'] as $package => $package_version) {
        $this->install_package($package, $package_version, $path, $install_path);
      }

      $this->print('Done!');

      return true;
    }

    return false;
  }

  public function install_package($package, $version = null, $path = null, $install_path = null, $save = false) {
    $dir = dirname($path ?: '.');
    $install_path = $install_path ? rtrim($install_path, '/') : "$dir/gpm_modules";
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

    $this->create_directory($install_path);

    if (!$version) {
      $versions = $this->get_available_package_versions($package, 1);

      if ($versions) {
        $version = $versions[0];
      } else {
        $this->print("<lightred>Error</lightred>: Could not find the package <green>$package</green>.", STDERR);
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

    $this->print(" - <purple>Downloading</purple> <green>$package</green> (<brown>$download_url</brown>)...");
    $download_contents = @fopen($download_url, 'r', false, $context);

    if (!$download_contents && $alt_download_url) {
      $download_url = $alt_download_url;
      $this->print(" - <purple>Downloading</purple> <green>$package</green> (<brown>$download_url</brown>)...");
      $download_contents = @fopen($download_url, 'r', false, $context);
    }

    if (!$download_contents) {
      $versions = $this->get_available_package_versions($package, 5);

      if ($versions) {
        $versions = implode(', ', $versions);
        $this->print("<lightred>Error</lightred>: Unable to find version <brown>$version</brown> of package <green>$package</green>. Found: $versions...\n", STDERR);
      } else {
        $this->print("<lightred>Error</lightred>: Could not find the package <green>$package</green>.\n", STDERR);
      }

      return false;
    }

    if (!file_exists($tmp_path)) mkdir($tmp_path);
    if (!file_exists($tmp_author_path)) mkdir($tmp_author_path);
    if (!file_exists($author_path)) mkdir($author_path);
    if (file_exists($tmp_package_path)) unlink($tmp_package_path);

    file_put_contents($tmp_package_path, $download_contents);
    fclose($download_contents);

    $this->print(" - <cyan>Extracting</cyan> <green>$tmp_package_path</green>...");

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

    $this->clean_directory($tmp_path, true);

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
