# Changelog

## [1.0.1](https://github.com/akaienso/post-domain/compare/v1.0.0...v1.0.1) (2026-08-30)


### Bug Fixes

* make the domain management screen usable ([#5](https://github.com/akaienso/post-domain/issues/5)) ([0d7fea6](https://github.com/akaienso/post-domain/commit/0d7fea6c41a16f567ff5d4ebaab77f424fc83f8c))

## 1.0.0 (2026-08-29)


### Features

* **admin:** settings, mapping list, domain detail, and diagnostics ([290b2ee](https://github.com/akaienso/post-domain/commit/290b2ee1dc9acdbe6f87ed406ef4252d0fbda99d))
* map a domain to a post and resolve it in place ([9ea805f](https://github.com/akaienso/post-domain/commit/9ea805f956ab9f4b472d94fa69ab7e25f3522920))
* **rest:** the management API, registered only on the primary host ([5ca784a](https://github.com/akaienso/post-domain/commit/5ca784a032c7780b9eb257405ec1b8c5f1b4f3ce))
* **ssl:** driver-backed lease recovery and the reconciliation sweep ([5388ea6](https://github.com/akaienso/post-domain/commit/5388ea6c06fd0a3824a2bef4d33733c3c8038d2f))
* **ssl:** provider mutation services and the Cloudflare for SaaS driver ([2e1b04e](https://github.com/akaienso/post-domain/commit/2e1b04effff31880aafbe327789464f5d1343c9c))


### Bug Fixes

* fail closed on unreadable scopes, lease writes, and clone reset ([13dec70](https://github.com/akaienso/post-domain/commit/13dec701329e352a62f012d09b8a32083b64f55b))
* resume SSL-only removals, unblock continuations, key DoH quorum by authority ([3ed1fc3](https://github.com/akaienso/post-domain/commit/3ed1fc3db945dae55b7473a09204b1d6668e2979))
* six correctness defects in deletion, verification, cron, and rebasing ([84a95cf](https://github.com/akaienso/post-domain/commit/84a95cf0551bab2347dff822f0a55129d75e261a))
* **test:** keep the suite within the declared PHP floor ([08f0781](https://github.com/akaienso/post-domain/commit/08f0781db7be4911db0cce4189f687a5f34c5d28))
* **url:** validate a filtered rebase result strictly ([24b4ed3](https://github.com/akaienso/post-domain/commit/24b4ed3889f0a316aac9adeacee2bee754a4de92))

## Changelog

All notable changes to this plugin are documented here.

This file is maintained by Release Please. Entries are generated from
[Conventional Commits](https://www.conventionalcommits.org/) on `main`; do not
edit it by hand.
