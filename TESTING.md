# Testing

The plugin-level tooling — stub tests, the local WordPress matrix, the
updater probe — is documented in [`tests/README.md`](tests/README.md).
That is what CI runs and what to run before tagging a release.

The end-to-end plan that drives this plugin through the SEO tools (six
layers from unit tests to one real post, with rollback per change) lives
in the private 4all-automations repo:
`documentation/seo-test-plan.md` (local checkout:
`C:\Users\doug\WebstormProjects\4all-automations`). Its Layer 0 and Layer 1
are the plugin's; the rest is the tools. It is not copied here on purpose —
one authoritative file per document.
