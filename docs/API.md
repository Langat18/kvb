# CPD Attendance System: API Reference

## Read this first

The CPD Attendance System does not yet have an API that other systems can call. There is no API key, no versioned address space and no stable contract. This document describes what is there today, what is safe for another system to rely on, and what a proper partner API could look like.

There are three things an outside system might touch:

| What | Direction | Fit for other systems? |
| --- | --- | --- |
| Three small JSON endpoints that the system's own pages call | Into the CPD system | No. They depend on browser sessions or form tokens, and can change without notice. |
| Downloadable files: an event calendar entry and Excel or Word exports | Out of the CPD system | Partly. The calendar link is public and stable. The exports need a staff login. |
| The Kenya Veterinary Board PMS API | The CPD system calls it | Yes, but it belongs to the Board. It is documented here so the CPD side of that link is understood. |

The last section proposes a small read-only partner API. Nothing in it is built. It is there so the shape can be agreed before anyone writes code.

All addresses below are relative to the site root, for example `https://cpd.example.org`. Times are in the Africa/Nairobi time zone.

## What exists today

### Internal JSON endpoints

These serve the registration and event forms. They are listed so you know what they do, not as a contract. Each returns `Content-Type: application/json`.

#### Check whether a registration number is known

`GET /events/lookup.php?reg_no={number}`

No login. Used by the registration form as the practitioner types. It never returns full personal details: names, emails and phone numbers come back partly hidden.

| Situation | Response |
| --- | --- |
| Number missing or not a valid format | `{"found": false}` |
| Valid number, not in the local list | `{"found": false, "pms": true}`. The `pms` flag is true when PMS autofill is switched on and the number is in the newer format. |
| Number in the local list | `{"found": true, "masked": true, "name": "J*** K*****", "email": "j***@example.org", "phone": "07*****678"}` |

Accepted number formats are 4 or 5 digits, or `VTA`, `VTB` or `VTC` followed by 5 digits. Older formats are also recognised.

#### Look up a provider by accreditation number

`GET /events/provider_lookup.php?accreditation_no={number}`

Requires a staff session cookie. Used by the event form.

| Situation | Response |
| --- | --- |
| Found and active | `{"found": true, "id": 12, "name": "Example Veterinary Association"}` |
| Missing, unknown or inactive | `{"found": false}` |
| Not signed in | A redirect to the login page, not JSON. |

#### Fill registration details from the KVB register

`POST /events/pms_lookup.php`

Form-encoded body, not JSON. It needs the page's CSRF token, which is tied to the visitor's session, so it cannot be called from another system.

| Field | Meaning |
| --- | --- |
| `csrf_token` | The token embedded in the registration page. |
| `reg_no` | Registration number. |
| `dob` | Date of birth, as `2024-03-21` or `21/03/2024`. |

The reply is `{"status": "..."}`. Only `ok` carries data: `{"status": "ok", "data": {"name": "...", "email": "...", "phone": "..."}}`.

| Status | Meaning |
| --- | --- |
| `ok` | The date of birth matched the PMS record. Details returned. |
| `mismatch` | The date of birth did not match. |
| `not_found` | PMS has no such registration number. |
| `unverifiable` | PMS has the record but no usable date of birth. |
| `local` | The person is already in the local list. Nothing is fetched. |
| `limited` | Too many failed attempts. Repeated failures are limited per visitor address and per registration number. |
| `unavailable` | PMS could not be reached or answered badly. |
| `invalid` | The number or date was malformed, or the CSRF token was wrong (HTTP 403). |
| `off` | The autofill setting is switched off. |

### Files the system produces

| Address | Returns | Access |
| --- | --- | --- |
| `GET /events/calendar.php?token={event token}` | An iCalendar file (`.ics`) with the event's title, dates, venue, mode and coordinator, as a multi-day all-day entry. A missing token gives HTTP 400, an unknown one HTTP 404. | Public. Anyone with the event's token can fetch it. |
| `/events/print.php`, `/events/nominal_roll.php`, `/points/leaderboard.php`, `/points/print.php` | Excel (`.xlsx`) or Word (`.docx`) files: attendance register, nominal roll, leaderboard, a practitioner's points statement. | Staff login and the matching permission. Meant to be downloaded from the pages, not scripted. |

The event token is the one in the registration link, `…/events/register?token=…`. It is long and random, so it works as an unguessable key, but treat it as public once you have shared the link.

### The PMS API that this system calls

The CPD system is a client of the Board's PMS. These are the calls it makes, so that the Board or another developer can see exactly what is asked of PMS. Authentication is a bearer token supplied by the Board.

| Call | Used for |
| --- | --- |
| `GET {base}/registration/search?reg_number={number}` | Checking a registration number, and filling a registrant's details. |
| `GET {base}/cpd-activity/index` | The full list of approved CPD activities, copied nightly. |
| `GET {base}/cpd-provider/index` | The full list of CPD providers, copied nightly. |
| `GET {base}/cpd-activity/search?activity_number={number}` | One activity by its number. |
| `GET {base}/cpd-provider/search?accred_num={number}` | One provider by accreditation number. |

Every request carries `Authorization: Bearer {token}`, `Accept: application/json` and `User-Agent: KVB-CPD-Attendance/1.0`. The base address defaults to `https://mms.kenyavetboard.or.ke/api` and is set by `PMS_API_BASE`. Redirects are not followed, and the connection must be HTTPS unless it is to the local machine.

Every reply is expected in this shape. A missing record is `"success": false` rather than an error code.

```json
{
  "success": true,
  "message": null,
  "data": { }
}
```

The registration lookup is the only call that returns personal data. Fields the system reads, shown here with made-up values:

```json
{
  "success": true,
  "data": {
    "reg_number": "VTA00000",
    "category": "Veterinary paraprofessional - Bsc. Animal Health",
    "first_name": "Amina",
    "last_name": "Example",
    "surname": "Person",
    "dob": "1990-01-31",
    "email": "amina@example.org",
    "phone_number": "254700000000"
  }
}
```

The activity list gives, for each activity, `id`, `title`, `activity_number`, `activity_type`, `name_of_provider`, `name_of_cordinator` (spelled this way in PMS), `phone_number_cordinator`, `email_cordinator` and a `schedules` list of `venue`, `start_date` and `end_date`. The provider list gives `accred_num`, `name_of_provider`, `status` (approved, new or rejected), `accreditation_year`, `contact_person`, `telephone_number`, `email_address` and `date_added`.

How the system treats the replies: HTTP 200 with `success: true` is a found record, and `success: false` is not found. HTTP 401 or 403 means the token was rejected. Any other status, a timeout, or a body that is not the expected JSON is treated as PMS being unavailable, and the page degrades quietly. Lookups time out after 5 seconds and list downloads after 30. The system does not currently send anything back to PMS. Pushing CPD points to PMS is not available, because no endpoint for it has been provided.

## Proposal: a read-only partner API

Nothing in this section exists yet. It is a design to agree on before it is built. The aim is a small, stable interface that lets the Board's PMS, or another approved system, read attendance and points without scraping pages or touching the database.

### Ground rules

- **Read only.** Other systems can look at data. They cannot create or change events, attendance or points.
- **API keys.** An administrator creates a key for each partner and can switch it off at any time. A key is sent as `Authorization: Bearer {key}`. Keys are shown once, stored hashed, and every call is logged with the key that made it.
- **HTTPS only,** under `/api/v1/`. The version number in the address means a breaking change gets a new number, not a surprise.
- **Limited personal data.** Responses carry registration number, name and cadre. Phone numbers, emails, ID numbers and signatures are never returned.
- **JSON throughout,** with dates as `YYYY-MM-DD` and timestamps in ISO 8601 with the Nairobi offset.
- **Fair use.** 60 requests a minute per key. Lists are paged with `page` and `per_page` (default 50, at most 200).

### Proposed endpoints

| Endpoint | Returns |
| --- | --- |
| `GET /api/v1/events` | Events, newest first. Filters: `from`, `to`, `mode`, `provider`, `activity_number`. |
| `GET /api/v1/events/{id}` | One event with its sessions, venue and linked PMS activity. |
| `GET /api/v1/events/{id}/attendance` | Who attended each session, how (on site or virtual), whether it was verified, and the points awarded. |
| `GET /api/v1/practitioners/{reg_no}/points` | One practitioner's total and the events and sessions behind it. Filter by `from` and `to`. |
| `GET /api/v1/points?from=&to=` | Points awarded in a period across all events, for batch transfer into PMS. |
| `GET /api/v1/providers` | Providers and how many events each has run. |

An attendance row would look like this, with made-up values:

```json
{
  "data": [
    {
      "reg_no": "VTA00000",
      "name": "Amina Example Person",
      "cadre": "vet_bachelors",
      "session": "Session 1",
      "date": "2026-11-06",
      "method": "onsite",
      "verified": true,
      "points": 2.0
    }
  ],
  "page": 1,
  "per_page": 50,
  "total": 1
}
```

Only rows that were verified, or approved by a staff member, would appear in `/points` and in a practitioner's total. Held and denied sign-ins would be left out.

### Errors

Errors use the HTTP status and a short JSON body:

```json
{ "error": "not_found", "message": "No such event." }
```

| Status | `error` | When |
| --- | --- | --- |
| 400 | `bad_request` | A filter or date is malformed. |
| 401 | `unauthorized` | The key is missing, wrong or switched off. |
| 404 | `not_found` | The event or practitioner does not exist. |
| 429 | `rate_limited` | More than 60 requests in a minute. The `Retry-After` header says how long to wait. |
| 500 | `server_error` | Something broke on our side. |

### Decisions needed before building

- Which systems will consume it, so the fields match what they need.
- Whether PMS wants points pushed to it, or will pull them. If it pulls, `/api/v1/points` is enough. If it wants a push, PMS has to offer an endpoint to receive them, and the Board has not provided one yet.
- Who approves partner keys, and how often they are reviewed.
- Whether practitioner names may leave the system at all, or only registration numbers.
