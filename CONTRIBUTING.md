# Contributing to WP Crontrol

Bug reports, code contributions, and general feedback are very welcome. These should be submitted through [the GitHub repository](https://github.com/johnbillion/wp-crontrol). Development happens in the `develop` branch, and any pull requests should be made against that branch please.

## Reviews on WordPress.org

If you enjoy using WP Crontrol I would greatly appreciate it <a href="https://wordpress.org/support/plugin/wp-crontrol/reviews/">if you left a positive review on the WordPress.org Plugin Directory</a>. This is the fastest and easiest way to contribute to WP Crontrol 😄.

## Reporting Security Issues

[You can report security bugs through the official WP Crontrol Vulnerability Disclosure Program on Patchstack](https://patchstack.com/database/vdp/wp-crontrol). The Patchstack team helps validate, triage, and handle any security vulnerabilities.

Do not report security issues on GitHub or the WordPress.org support forums. Thank you.

## Inclusivity and Code of Conduct

Contributions to WP Crontrol are welcome from anyone. Whether you are new to Open Source or a seasoned veteran, all constructive contribution is welcome and I'll endeavour to support you when I can.

This project is released with <a href="https://github.com/johnbillion/wp-crontrol/blob/develop/CODE_OF_CONDUCT.md">a contributor code of conduct</a> and by participating in this project you agree to abide by its terms. The code of conduct is nothing to worry about, if you are a respectful human being then all will be good.

## Setting up Locally

You can clone this repo and activate it like a normal WordPress plugin. If you want to contribute to WP Crontrol, you should install the developer dependencies in order to run the tests.

### Prerequisites

* [Composer](https://getcomposer.org/)
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) if you want to run the tests
* [Node](https://nodejs.org/) if you are packaging a release

### Setup

Install the PHP dependencies:

	composer install

## Running the Tests

The test suite includes acceptance tests which run in a Docker container. Ensure Docker Desktop is running before running the tests.

To run the whole test suite which includes integration tests, acceptance tests, linting, and static analysis:

	composer test

To run tests individually, run one of:

	composer test:phpcs
	composer test:phpstan
	composer test:integration
	composer test:acceptance

To run individual tests:

	composer test:acceptance -- --codecept-args="tests/acceptance/AddEventCest.php"

The individual integration and acceptance tests require the Docker containers to be running. To start and stop them, use:

	composer test:start
	composer test:stop

## Releasing a New Version

These are the steps to take to release a new version of WP Crontrol (for contributors who have push access to the GitHub repo).

### Prior to Release

1. Check [the milestone on GitHub](https://github.com/johnbillion/wp-crontrol/milestones) for open issues or PRs. Fix or reassign as necessary.
1. If this is a non-patch release, check issues and PRs assigned to the patch or minor milestones that will get skipped. Reassign as necessary.
1. Ensure you're on the `develop` branch and all the changes for this release have been merged in.
1. Ensure `readme.md` and `readme.txt` contain up to date "Tested up to" versions, descriptions, FAQs, screenshots, etc.
1. Ensure `.gitattributes` is up to date with all files that shouldn't be part of the build.
   - To do this, export the archive then check the contents for files that shouldn't be part of the package:

         git archive --output=wp-crontrol.zip HEAD

1. Run the tests and ensure everything passes:

       composer test

1. Prepare a changelog for [the Releases page on GitHub](https://github.com/johnbillion/wp-crontrol/releases).

### For Release

1. Install the Node dependencies:

       npm install

1. Bump the plugin version number:
   - For a patch release (1.2.3 => 1.2.4):

         npm run bump:patch

   - For a minor release (1.2.3 => 1.3.0):

         npm run bump:minor

   - For a major release (1.2.3 => 2.0.0):

         npm run bump:major

1.     git push origin develop
1. Wait until (and ensure that) [the tests pass](https://github.com/johnbillion/wp-crontrol/actions)
1.     git checkout trunk
1.     git merge develop
1.     git push origin trunk
1.     git push origin trunk:release
1. Wait for [the Build Release action](https://github.com/johnbillion/wp-crontrol/actions/workflows/build.yml) to complete
1. Enter the changelog into [the release on GitHub](https://github.com/johnbillion/wp-crontrol/releases) and publish it.
1. Approve the release on [the WordPress.org release management dashboard](https://wordpress.org/plugins/developers/releases/).

### Post Release

Publishing a release on GitHub triggers an action which deploys the release to the WordPress.org Plugin Directory. No need to touch Subversion.

New milestones are automatically created for the next major, minor, and patch releases where appropriate.

1. Close the milestone.
1. If this is a non-patch release, manually delete any [unused patch and minor milestones on GitHub](https://github.com/johnbillion/wp-crontrol/milestones).
1. Check the new version has appeared [on the WordPress.org plugin page](https://wordpress.org/plugins/wp-crontrol/).
1. Resolve relevant threads on [the plugin's support forums](https://wordpress.org/support/plugin/wp-crontrol/).
1. Consume tea and cake as necessary.

### Asset Updates

Assets such as screenshots and banners are stored in the `.wordpress-org` directory. These get deployed as part of the automated release process too.

In order to deploy only changes to assets and the readme file, push the change to the `deploy` branch. This allows for the "Tested up to" value to be bumped as well as assets to be updated in between releases. Changes to files other than assets and the readme file will be ignored.
