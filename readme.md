# Git Package Manager

[![packagist package version](https://img.shields.io/packagist/v/itsahappymedium/gpm.svg?style=flat-square)](https://packagist.org/packages/itsahappymedium/gpm)
[![packagist package downloads](https://img.shields.io/packagist/dt/itsahappymedium/gpm.svg?style=flat-square)](https://packagist.org/packages/itsahappymedium/gpm)
[![license](https://img.shields.io/github/license/itsahappymedium/gpm.svg?style=flat-square)](license.md)

A PHP Command Line tool that makes it easy to download dependencies from GitHub.


## Use-Case

When building a site in PHP, the two main options to downloading and installing front-end frameworks is to either use NPM/Yarn/Bower (Which require Node), or take the source of the framework and include it in the project manually (and committing that code to your repo). The goal with this tool is to eliminate the need for Node as well as having to commit the framework's code to your project's repo.


## Installation

```
composer require itsahappymedium/gpm
```

After that, it wouldn't be a bad idea to add the following to your `composer.json` file:

```json
"scripts": {
  "gpm": "vendor/bin/gpm"
}
```

## Usage

GPM will read a list of dependencies from a `gpm.json` file that is structured like so:

```json
{
  "dependencies": {
    "cowboy/jquery-throttle-debounce": "1.1",
    "derek-watson/jsuri": "1.3.1",
    "kazzkiq/balloon.css": "1.0.0",
    "kraaden/autocomplete": "#c43f2a7",
    "leongersen/nouislider": "14.7.0",
    "patrickkunka/easydropdown": "4.2.0",
    "zenorocha/clipboard.js": "2.0.8"
  }
}
```

Package names are `<github_username>/<repo_name>`. So if you wanted to install https://github.com/kraaden/autocomplete, the package name would be `kraaden/autocomplete`.

Package versions can be a tag name, `dev-<branch>`, `#<commit_sha>`, or a URL (Zip files will be extracted). If a package version isn't found using the version/tag specified, it will try again with a `v` prepended (For example, if `1.3.1` doesn't exist, it will also try `v1.3.1`).


### `gpm install [package] [--save/-s] [--path/-p <path>] [--install-path/-i <path>]`

If the `package` argument is passed, it will simply download and extract that package (also saving it to `gpm.json` if the `--save` or `-s` option is set), otherwise if no arguments are passed, all packages currently defined in `gpm.json` will be downloaded and extracted.

Set the `--path` or `-p` option to define a path to where the `gpm.json` file is located (Defaults to the current directory).

Set the `--install-path` or `-i` option to define a path to download and extract packages to (Defaults to `gpm_modules` in the current directory).


### `gpm uninstall <package> [--save/-s] [--path/-p <path>] [--install-path/-i <path>]`

Deletes a package from the `gpm_modules` directory (or whatever directory the `--install-path` or `-i` option is set to) (also removing it from `gpm.json` if the `--save` or `-s` option is set).


### `gpm versions <package>`

Lists the versions available for the package passed to the `package` argument.


### `gpm init [--path/-p <path>]`

Generates a `gpm.json` file in the current directory (or whatever directory the `--path` or `-p` option is set to).


## License

MIT. See the [license.md file](license.md) for more info.
