# Releasing a New Version

These are the steps to take to release a new version of WP Crontrol (for contributors who have push access to the GitHub repo).

## Prior to Release

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

## For Release

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
1.     git push origin develop:release
1. Wait for [the Build Release action](https://github.com/johnbillion/wp-crontrol/actions/workflows/build.yml) to complete
1. Enter the changelog into [the release on GitHub](https://github.com/johnbillion/wp-crontrol/releases) and publish it.
1. Approve the release on [the WordPress.org release management dashboard](https://wordpress.org/plugins/developers/releases/).
1.     git push origin develop:trunk

## Post Release

Publishing a release on GitHub triggers an action which deploys the release to the WordPress.org Plugin Directory. No need to touch Subversion.

New milestones are automatically created for the next major, minor, and patch releases where appropriate.

1. If this is a non-patch release, manually delete any [unused patch and minor milestones on GitHub](https://github.com/johnbillion/wp-crontrol/milestones).
1. Resolve relevant threads on [the plugin's support forums](https://wordpress.org/support/plugin/wp-crontrol/).

## Asset Updates

Assets such as screenshots and banners are stored in the `.wordpress-org` directory. These get deployed as part of the automated release process too.

In order to deploy only changes to assets and the readme file, push the change to the `deploy` branch. This allows for the "Tested up to" value to be bumped as well as assets to be updated in between releases. Changes to files other than assets and the readme file will be ignored.
