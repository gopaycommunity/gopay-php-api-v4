// semantic-release config
// Consistent with other GoPay repos: no 'v' prefix, PHP lib so no npm publish.
export default {
  branches: ['master'],
  tagFormat: '${version}',
  plugins: [
    ['@semantic-release/commit-analyzer', {
      preset: 'conventionalcommits',
      releaseRules: [
        { type: 'feat',     release: 'minor' },
        { type: 'fix',      release: 'patch' },
        { type: 'perf',     release: 'patch' },
        { type: 'refactor', release: 'patch' },
        { breaking: true,   release: 'major' },
      ],
    }],
    ['@semantic-release/release-notes-generator', {
      preset: 'conventionalcommits',
    }],
    ['@semantic-release/changelog', {
      changelogFile: 'CHANGELOG.md',
    }],
    ['@semantic-release/exec', {
      // No npm publish for a PHP library, but keep version field in sync
      // so CHANGELOG.md shows the correct next version.
      prepareCmd: 'echo "Releasing version ${nextRelease.version}"',
    }],
    ['@semantic-release/git', {
      assets: ['CHANGELOG.md'],
      message: 'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
    }],
  ],
};
