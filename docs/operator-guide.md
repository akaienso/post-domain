# Operator guide

This is the guide for whoever actually maps the domains. It assumes you can edit
DNS records for the domain, and it assumes nothing at all about certificates,
Cloudflare, or how any of this works underneath.

The same material is available inside WordPress, on the **Domain mappings**
screen: the **Setup guide** panel on the page itself, and the **Help** tab in the
top corner. You do not need this file to do the job — it is here for reading
away from the admin, for handing to a colleague, and for printing.

Nothing here requires you to touch code or configuration files. The one step that
is *not* in your hands, and the one that most often goes wrong, is the hosting
step in [Before you begin](#before-you-begin-your-hosting-must-accept-the-domain).
Read that first.

---

## Contents

1. [What a mapped domain does](#what-a-mapped-domain-does)
2. [Before you begin: your hosting must accept the domain](#before-you-begin-your-hosting-must-accept-the-domain)
3. [The parts involved, and which is which](#the-parts-involved-and-which-is-which)
4. [Which DNS records are permanent and which are temporary](#which-dns-records-are-permanent-and-which-are-temporary)
5. [Why the ownership record has to stay](#why-the-ownership-record-has-to-stay)
6. [Two proofs that look alike but are not](#two-proofs-that-look-alike-but-are-not)
7. [Why a certificate can take a while](#why-a-certificate-can-take-a-while)
8. [Countdowns and automatic rechecks](#countdowns-and-automatic-rechecks)
9. [How to test the mapped domain](#how-to-test-the-mapped-domain)
10. [Removing a mapped domain, in the right order](#removing-a-mapped-domain-in-the-right-order)
11. [Copied sites, staging, and restores from backup](#copied-sites-staging-and-restores-from-backup)
12. [The certificate is active but the domain does not show the right page](#the-certificate-is-active-but-the-domain-does-not-show-the-right-page)

---

## What a mapped domain does

A mapped domain shows one page from this WordPress site at a different domain
name. A visitor types the mapped domain and sees the page you chose.

The address bar keeps showing the mapped domain the whole time. Nobody is
redirected: the visitor is never sent to this site's main address, and the page's
usual path never appears.

The domain is **resolved, not forwarded**. That difference is the entire point of
the feature. If a visitor ends up looking at your main domain, something is
wrong — not merely untidy.

Pages filed underneath the page you mapped are reachable underneath the mapped
domain in the same way.

---

## Before you begin: your hosting must accept the domain

**This is the step that most often goes wrong, and it is the one step the plugin
cannot perform for you.**

The plugin can prove you control the domain, have a certificate issued for it,
and turn serving on. When all three are done the screen will say **verified**,
**serving**, and **certificate active**, and the domain really will answer
securely over HTTPS.

None of that makes your web host hand the request to this WordPress site.

Whatever answers requests for your site — the web server, the hosting platform, a
reverse proxy, or a CDN in front of it — has to recognise the mapped domain as one
of its own names and route it to **this same WordPress installation**.

It must not rewrite the domain back to your main domain along the way. Many hosts
offer an alias mode that simply **forwards** visitors to the primary domain.
**Do not use that mode.** Forwarding replaces the mapped domain in the address bar
and defeats the whole reason for mapping it in the first place. The alias has to
*serve* the domain, not redirect it.

The name of the setting differs by host:

| You may see it called | Where |
|---|---|
| Domain alias, additional domain, parked domain (serve, not redirect) | Hosting control panel |
| Virtual host, server block, server alias | Apache or nginx |
| Origin host, host header override, custom hostname | CDN or reverse proxy |

If you are not sure which of these your host means, ask them exactly this: *"How
do I serve one more domain from this same site, without redirecting it to the
primary domain?"*

### You can recognise this step being missing by any of these

- A secure address quietly becomes an insecure one — you asked for HTTPS and got
  plain HTTP.
- The address bar changes to your main domain.
- You get the hosting company's generic welcome, parked-domain, or "site not
  found" placeholder page instead of your page.

This has actually happened in testing: every indicator in the plugin was green,
the certificate was valid in a browser, and the domain still showed the host's
placeholder page — because the request never reached WordPress at all under the
mapped name.

---

## The parts involved, and which is which

Six things have to line up. They are easy to confuse, because several of them are
some flavour of "where the domain points", so it is worth being clear about what
each one actually is.

| Part | What it is |
|---|---|
| **The WordPress target** | The page you pick in the plugin. What a visitor to the mapped domain ends up seeing. |
| **Authoritative DNS** | The service that answers questions about your domain and holds its records — usually your registrar or a DNS host. This is where you add every record the plugin asks for. |
| **The certificate service** (Cloudflare for SaaS) | Issues the certificate for the mapped domain and answers the secure connection on its behalf. It does not have to be the same company as your DNS, and it is not your hosting. |
| **The CNAME target** | The name your domain is pointed at, so requests arrive at the certificate service rather than going straight to your hosting. The plugin tells you the exact value. |
| **The fallback origin** | Where the certificate service passes a request on to once it has answered it. Configured once, at the certificate service, and it points at your hosting. A different value from the CNAME target; the two are not interchangeable. |
| **The WordPress origin server** | This site's own hosting, which finally produces the page. This is the part that has to be told about the mapped domain. |

Read left to right, a request goes: visitor → authoritative DNS says where to go →
certificate service answers securely → fallback origin → WordPress origin server →
the page.

---

## Which DNS records are permanent and which are temporary

You will add up to four records, with four different jobs and four different
lifetimes. They are not interchangeable, and removing the wrong one is the most
common way to break a working mapping.

| Record | Lifetime |
|---|---|
| **The ownership record** — a `TXT` record at `_post-domain-challenge.<your domain>` | **Permanent.** It must stay for as long as the mapping exists. |
| **The routing record** — the `CNAME` pointing the domain at the certificate service, or the equivalent arrangement for a bare domain | **Permanent.** Remove it and the domain stops working immediately. |
| **The certificate service's own hostname-ownership record** | **Temporary.** Once the service reports the domain as active, it may be removed. |
| **The certificate validation record** | **Controlled by the certificate service, and possibly wanted again.** It may go once the certificate has been issued, but a renewal can ask for it again. Being asked for it a second time, months later, is normal — not a fault. |

If you would rather not think about it: leaving all four in place is always safe.
Only the ownership and routing records are *required* to stay.

---

## Why the ownership record has to stay

Before doing anything privileged with your domain — **including deleting its
certificate** — the plugin checks again that the ownership `TXT` record is still
where it put it. Proving control once, at the start, is not enough; it is
re-proved each time it matters.

That check is what stops some other installation, or a copy of this one, from
reaching over and interfering with a certificate that is not its own.

So the ownership record is not a one-time formality you can tidy away once the
domain verifies. Remove it early and the plugin can no longer prove ownership,
which means it will **refuse** to clean the certificate up. The mapping is then
stranded: it can neither finish nor be removed cleanly.

Putting the record back lets it recover. Remove the ownership record only **after**
the mapping has been deleted in the plugin.

---

## Two proofs that look alike but are not

The certificate service asks for two records that look very similar. They answer
different questions, so answering one does not answer the other.

- **Hostname ownership** asks: *may this domain be attached to this certificate
  account at all?*
- **Certificate validation** asks: *may a certificate be issued for this domain,
  now?*

Different questions, different records — and not always at the same moment. It is
normal to add one, wait, and only then be asked for the other. Neither one being
satisfied tells you anything about the other.

Add whichever record the screen is currently asking for, rather than assuming a
record you added earlier already covered it.

---

## Why a certificate can take a while

A DNS record is not visible everywhere the moment you save it. It has to spread
across the internet first, and the certificate service then has to see it and run
its own checks.

A few minutes is normal. Longer is not unusual with a slow DNS provider, and
neither is a wait that seems to sit still for a while and then complete all at
once.

While that is happening, the plugin waits. It deliberately **does not** keep
asking for a new certificate, because starting over restarts the clock and makes
issuance slower rather than faster. A screen that says it is waiting is working.

---

## Countdowns and automatic rechecks

Asking to check a domain again looks at your DNS afresh. The server allows **one
such check per domain per minute**; ask sooner and it will tell you plainly to
wait.

The countdown on screen is a convenience, so you know roughly when the button
becomes useful again. It is not the rule — the server enforces the wait, and the
server is the one that decides. If the countdown and the server ever disagree,
believe the server.

Separately, the plugin checks in with the certificate service on its own schedule,
in the background. You do not have to sit and watch the page. Close it, come back
later, and any progress made in the meantime will be there.

---

## How to test the mapped domain

Once the screen reports the domain as verified, serving, and with an active
certificate, test it properly:

1. Open the mapped domain over `https://` in a browser window that is **not
   logged in** to WordPress. A private or incognito window is enough.
2. Check that the page shown is the one you mapped.
3. Check that the address bar **still shows the mapped domain**, and did not
   change to your main domain.
4. Check that the browser still shows the connection as **secure**, and did not
   fall back to an insecure one.
5. Follow one link into a page filed underneath the mapped page, and confirm it
   also stays on the mapped domain.

Test from outside your own network if you can, and from a device that has never
visited the site. A leftover entry in your computer's hosts file, or a cached
page, can make a broken setup look perfect.

If any check fails while the plugin still reports everything as fine, read
[Before you begin](#before-you-begin-your-hosting-must-accept-the-domain) before
changing anything in the plugin.

---

## Removing a mapped domain, in the right order

Removal has an order. Doing it out of order strands the mapping and leaves a
certificate behind that nothing on this site can tidy up.

1. **Stop serving** the domain, so visitors are no longer being sent to it.
2. **Remove the certificate** from the plugin, so the certificate service releases
   the domain.
3. **Delete the mapping.**
4. **Only then remove the DNS records** at your DNS provider.

The DNS records go last because the ownership record has to still be present for
the plugin to prove it is allowed to remove the certificate. Delete the records
first and that proof is gone: the certificate stays at the provider, and nothing
on this site can clean it up any more.

---

## Copied sites, staging, and restores from backup

Copying a site copies its mappings too. Making a staging copy, cloning to a new
host, or restoring an old backup all produce a second installation that believes
it owns the same domains and the same certificates as the original.

That second installation **must not** act on the original's certificates. If it
did, routine work on a staging copy could delete a live site's certificate.

The plugin notices when it is running as a copy rather than as the installation
that set the mappings up. When it does, it **stands down**: it keeps showing you
what it knows, but it stops making changes at the certificate service and asks an
operator to decide.

Decide deliberately, and only from one installation:

- On a **staging or test copy**, release the mappings, so the copy stops claiming
  domains that belong to the live site.
- On a **genuine move**, where the original installation is gone for good, take
  the mappings over so the new installation is the one in charge.

Never take them over from two installations at once. If you are unsure which
installation you are looking at, check the site address before deciding.

---

## The certificate is active but the domain does not show the right page

If the plugin reports verified, serving, and an active certificate, then the
domain name, the certificate, and the plugin's own records are all in order. What
is left is the path between the certificate service and this WordPress site —
which is hosting configuration, not something to fix from the plugin's screen.

| What you see | What it usually means |
|---|---|
| A generic welcome, parked-domain, or "site not found" page | The hosting has not been told to serve this domain from this site. |
| The address bar changes to your main domain | Something is forwarding — usually a host alias set to redirect rather than to serve, or a canonical-URL setting in a CDN, a caching layer, or another plugin. |
| A secure address becomes an insecure one | The request is arriving somewhere that does not know about the mapped domain. |
| The browser warns about a certificate for some other domain | The request is not reaching the certificate service at all. Check the routing record at your DNS provider. |
| The right page loads, but links or images point at the main domain | A caching layer, or a hard-coded address in the content — not the mapping. |
| Nothing loads and the domain does not resolve | The routing record is missing, or has not spread yet. |

In each of those, start with the hosting configuration for the mapped domain,
then check the plugin's diagnostics for anything it has already flagged. Clear
any caching layer in front of the site before re-testing, so you are not looking
at an old answer.

## 12. Hosting, certificates and DNS are three different things

They are easy to confuse because all three are "where the domain points", and
they can be three different companies.

| | What it does | Who it is |
|---|---|---|
| **Hosting / origin** | Finally serves your page. It has to recognise the mapped domain as one of its own names. | Wordify, or whoever runs your site |
| **Certificate / edge** | Issues the certificate and answers the secure connection. | Cloudflare for SaaS |
| **Authoritative DNS** | Answers questions about your domain. | Your registrar or DNS host — anywhere |

Your mapped domain's DNS does not have to be in the same Cloudflare account as
anything else. Post Domain never assumes it is.

### If your site is on Wordify

Post Domain can tell Wordify about each mapped domain for you, so the origin
accepts it. To do that it needs a Wordify API token that **you** create — the
plugin has no credential of its own and never will.

1. In the Wordify console, create an API token.
2. Tick exactly two abilities: **Read Sites** and **Manage Sites**. Both are
   required — reading alone finds your site but cannot attach a domain to it.
   Do not tick full access; Post Domain never needs your billing, your
   primary domain, or your site's settings.
3. Paste it into **Settings → Domain mappings → Hosting provider**.
4. Choose **Test connection**. Post Domain reads who the token belongs to and
   lists the sites it can see. Nothing is changed at Wordify. If your token can
   act for more than one Wordify team, you are asked which one first — Post
   Domain will not pick for you, and only the teams your token actually names
   are offered.
5. Pick which Wordify site this WordPress installation is, and tick the box
   confirming it. The list is searchable and paged, so an account with hundreds
   of sites stays usable. Post Domain then reads that exact site back with your
   token before it binds anything.

**What Test connection can and cannot tell you.** It proves the token
authenticates, that your teams and sites can be read, and that the site you
picked is one this token can see. It cannot prove the token has **Manage
Sites**, because Wordify offers no read-only way to ask what a token is
allowed to do, and Post Domain will not attach a throwaway domain to find
out. If the ability is missing, you will learn it the first time you add a
domain: the attach step stops with a message telling you to add **Manage
Sites** to the token. Nothing is half-done and nothing is retried blindly.

Until that connection is made and bound to one site, the **Add a domain** form
is not shown. That is deliberate. A domain added without it would verify, get a
certificate, and then show your host's placeholder page — a failure that looks
like everything worked.

**What Post Domain never does to your Wordify site.** It never makes a mapped
domain primary. It never changes your main site's domain, your WordPress
address, or your site address. It never changes your DNS.

**If the token stops working** — revoked, expired, or scoped too narrowly — you
will not be able to add new domains until you replace it. Every domain already
serving keeps serving. A failed read never changes anything that was already
set up.

**Disconnecting** removes Post Domain's permission to make further changes on
your behalf. It does not detach any domain from Wordify and does not delete any
mapping.

**Deleting a mapping.** No domain-detachment operation appears anywhere in
Wordify's published API surface. So deleting a mapping here removes the mapping
and then tells you — by name — which hostname is still attached to which Wordify
site, so you can remove it in the console yourself. Post Domain will not guess at
an operation it cannot verify, and never implies it tidied up at Wordify.

**What happens when you add a domain.** Post Domain records the domain locally,
marks it as claimed for one attachment, and then makes exactly one call asking
Wordify to accept the hostname. It never asks twice. If Wordify does not answer,
the domain is left in a "not confirmed" state and Post Domain settles it later by
*reading* — it never repeats the attachment. Until Wordify confirms, the setup
screen says so plainly rather than calling the domain ready.

The message you get says which of three things happened. If Wordify accepted the
domain, that is a plain success. If it did not answer, the domain is added and
marked unconfirmed, and Post Domain settles it later by checking. If Wordify
refused — usually a token missing **Manage Sites** — you get a failure, the
mapping is kept, and once you have fixed the token you can choose **Ask your
hosting again** on that domain rather than deleting and rebuilding it.

### If your site is hosted anywhere else

Choose **Manual or another host**. No token is needed and no hosting API is
contacted. You arrange for your web server, panel or CDN to accept the mapped
domain, as section 2 describes.

### Keeping the token out of harm's way

The token is stored encrypted, and is never shown again once saved — only the
fact that one is configured. If you would rather it never touched the database,
define it in `wp-config.php` instead:

```php
define( 'PD_WORDIFY_TOKEN', 'your-token-here' );
```

A token defined there takes precedence and cannot be changed from the admin
screen.

Encryption is a second line of defence, not the first. Someone with full
database *and* filesystem access can decrypt it, so the real protection is
scope: give the token only the abilities listed above, so that even in the worst
case it cannot do much.
