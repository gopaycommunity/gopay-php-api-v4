// semantic-release config
// Consistent with other GoPay repos: no 'v' prefix, PHP lib so no npm publish.

// Release notes link to the public GitHub mirror, never to the internal Bitbucket repository.
// semantic-release builds the commit and compare links from repositoryUrl, which stays the
// Bitbucket origin because that is where it pushes the release commit and tag. The notes end up
// in CHANGELOG.md, in the release commit message and in every Packagist zip, all of them public,
// so they are pointed at the mirror instead. The mirror is a force-push of Bitbucket master and
// its tags, so every commit hash and tag the notes name exists there.
const GITHUB_REPOSITORY_URL = 'https://github.com/gopaycommunity/gopay-php-api-v4';

// "#12" and "@name" in a commit name a Bitbucket pull request or user, which mean nothing on the
// mirror. The preset's transform links them from the Bitbucket context before finalizeContext
// runs, so they are turned back into plain text here.
const unlink = (text) => (typeof text === 'string' ? text.replace(/\[([#@][^\]]*)\]\([^)]*\)/g, '$1') : text);

const linkToGitHub = (context) => ({
  ...context,
  host: 'https://github.com',
  owner: 'gopaycommunity',
  repository: 'gopay-php-api-v4',
  commit: 'commit',
  commitGroups: context.commitGroups.map((group) => ({
    ...group,
    // "closes #12" references name Bitbucket pull requests too.
    commits: group.commits.map((commit) => ({ ...commit, subject: unlink(commit.subject), references: [] })),
  })),
  noteGroups: context.noteGroups.map((group) => ({
    ...group,
    notes: group.notes.map((note) => ({ ...note, text: unlink(note.text) })),
  })),
});

// Inline plugin, listed after the notes generator: fails the release, and the PR's dry-run
// preview, if the notes still name an internal host. generateNotes runs in dry-run too and gets
// the notes the plugins before it produced; returning nothing adds nothing to them.
const assertPublicNotes = {
  generateNotes: (_pluginConfig, { nextRelease }) => {
    const match = /bitbucket\.org|atlassian\.net/i.exec(nextRelease.notes ?? '');
    if (match) {
      throw new Error(`Release notes name the internal host ${match[0]}. They are public (CHANGELOG.md, the release commit, Packagist), so they may only link to ${GITHUB_REPOSITORY_URL}.`);
    }
  },
};

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
      writerOpts: { finalizeContext: linkToGitHub },
    }],
    [assertPublicNotes],
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
