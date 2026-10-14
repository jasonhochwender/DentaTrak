# Draft: Built-in Bridge Request to Open Dental Vendor Relations

**Status:** DRAFT for review — not sent.
**To:** vendor.relations@opendental.com
**Subject:** Request for built-in Program Link / bridge inclusion — DentaTrak

---

Hello Open Dental Vendor Relations team,

My name is [Name], and I'm writing on behalf of DentaTrak
(https://dentatrak.com), a dental lab case tracking platform used by dental
practices to follow cases through the lab workflow.

DentaTrak already integrates with Open Dental through your official REST
API: with the practice's authorization and a Developer API key, we
synchronize LabCases from Open Dental into DentaTrak so practices can track
case status, due dates, and lab communication in one place.

We have also implemented patient-specific access using your documented
Custom Bridge functionality. Practices configure a Program Link that opens
our launch URL with the selected patient's PatNum
(`https://dentatrak.com/od-launch.php?pat=[PatNum]`), which takes the user
directly to that patient's lab cases in DentaTrak. This works well, and we
understand the Custom Bridge will remain necessary for practices on older
versions.

To make setup as seamless as possible for new practices, we would like to
be considered for inclusion as a built-in Program Link / bridge in a future
Open Dental release, alongside the existing non-imaging bridges. Could you
share:

1. The process for requesting and reviewing built-in bridge inclusion, and
   whom we should work with.
2. The technical requirements — e.g., required fields, supported argument
   substitutions, toolbar placement conventions, icon format requirements,
   and any constraints on the launch URL (our launch is a public HTTPS URL,
   which we believe matches the URL bridge pattern used by several listed
   bridges).
3. Any documentation, testing, or verification you require from us,
   including supported Open Dental version ranges and how compatibility is
   validated.
4. Whether inclusion involves any fees or agreements beyond our existing
   Developer API key arrangement.
5. Anticipated timing — which release a new bridge could realistically ship
   in, and how enabled/disabled defaults and toolbar selection are handled
   for newly added bridges.

To be clear: this message is a request for information about the process.
It does not commit DentaTrak to any paid services or agreements, and we are
not asking Open Dental to modify anything on our behalf.

Thank you for your time. We're happy to provide whatever technical detail,
test access, or documentation would help the review.

Best regards,
[Name]
[Title]
DentaTrak — https://dentatrak.com
[Contact email] · [Phone]
