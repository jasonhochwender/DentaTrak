# Adding the DentaTrak Button in Open Dental

DentaTrak connects to Open Dental as a **Custom Bridge** (Program Link). Once
configured, a **DentaTrak** button appears on the Open Dental toolbars you
choose. Selecting a patient and clicking the button opens DentaTrak in your
default browser, already filtered to that patient's lab cases.

No changes to Open Dental itself are required - this uses Open Dental's
built-in Program Links feature.

> **This shortcut is separate from synchronization.** The toolbar button is
> an optional convenience layered on top of the API connection. Case import,
> automatic updates, and write-back all work without it - the button simply
> gives staff a one-click jump from a selected patient into DentaTrak.

## What you need

- The **Open Dental integration must already be connected** for your practice
  in DentaTrak (Settings → Integrations → Open Dental). The button opens
  patient lab cases that have been synced from Open Dental; it does not
  create the connection.
- A DentaTrak user account with access to the practice. Staff sign in with
  their normal DentaTrak login (including 2FA, if enabled) the first time
  they click the button in a browser session.
- Open Dental version **22.1 or newer** recommended. The button and the
  `[PatNum]` patient substitution work on all versions that support
  Program Links; the lab-case sync itself requires a version recent enough
  for the connected integration (API events).

## Setup (one time per office)

The Program Link is stored in the shared Open Dental database, so you only
need to do this once - every workstation gets the button.

> **Tip:** the same step-by-step instructions, with each field value ready
> to copy and the icon ready to download, appear directly in DentaTrak under
> **Settings → Integrations → Open Dental** once your practice is connected.
> This document is the printable/reference version.

1. In Open Dental, go to **Main Menu → Setup → Program Links**.
2. Click **Add** to create a custom bridge.
3. Check the **Enabled** box - the button only appears while the bridge is
   enabled.
4. Fill in the fields exactly as follows:

   | Field | Value |
   |---|---|
   | **Description** | `DentaTrak` |
   | **Enabled** | checked |
   | **Path of file to open** | `https://YOUR-DENTATRAK-HOST/od-launch.php?pat=[PatNum]` |
   | **Local Path Override** | *(leave blank)* |
   | **Optional command line arguments** | *(leave blank)* |
   | **Text on button** | `DentaTrak` |

   Replace `YOUR-DENTATRAK-HOST` with your DentaTrak address, for example
   `app.dentatrak.com` or your clinic's custom domain.

   > **Important:** the `?pat=[PatNum]` part must be at the end of the *Path
   > of file to open* value - not in *Optional command line arguments*. Open
   > Dental substitutes `[PatNum]` inside the Path field, but values in the
   > arguments field are handed to the browser as separate process arguments
   > and never reach the URL.

5. (Optional) Set the button icon: click **Import** under *Button Image* and
   select `images/dentatrak-od-bridge-22.png` from the DentaTrak install
   folder (a 22×22 icon is required format).
6. Under **Add a button to these toolbars**, select where the button should
   appear. Recommended: **Main Toolbar** (available from every module) or
   **ChartModule**/**FamilyModule** (where staff already work with the
   selected patient).
7. Click **OK** / **Save**.

## Using it

1. Select a patient in Open Dental.
2. Click the **DentaTrak** toolbar button.
3. Your default browser opens:
   - If you're signed in to DentaTrak, you go straight to the case list
     filtered to that patient's synced lab cases.
   - If not, you sign in (and complete 2FA / practice selection if needed),
     then land on the same filtered view automatically.
4. A banner at the top of the case list shows whose cases you're viewing.
   Click **Show all cases** to return to the full list.

If the patient has no synced lab cases yet, the list shows all cases and a
notice explains that nothing was found for the patient - cases appear after
the next sync/import.

## Notes and limitations

- **Always select a patient first.** If no patient is selected, Open Dental
  substitutes an empty `[PatNum]` and DentaTrak opens its normal case list.
- The button opens whichever patient is selected in Open Dental; it resolves
  through the practice's existing integration mappings. Patients who have no
  imported lab cases will not have a filtered view yet.
- The patient number in the launch URL is only a hint - it grants no access.
  DentaTrak still requires sign-in and resolves patients only inside the
  signed-in user's own practice. Staff from a different practice (or a
  practice with no Open Dental connection) never see another office's data.
- Users who belong to multiple DentaTrak practices resolve the patient in
  whichever practice they are currently working in.
- This bridge does not require any additional Open Dental API permissions or
  fees beyond the existing integration.
- Terminal Server / Remote Desktop environments: the button opens the
  default browser *on the machine Open Dental is running on*. If staff run
  Open Dental in a remote session but want a local browser, a tool such as
  Remote Executer (documented in Open Dental's Program Links page) can
  redirect the launch to the local machine.
- **Open Dental Cloud (unverified):** Open Dental Cloud runs the
  OpenDental.exe desktop inside Open Dental's hosted virtual machine, so
  the button is expected to open a browser *inside that hosted session*
  rather than the staff member's own browser. DentaTrak sign-in would then
  need to happen in that session's browser, and it does not share the
  user's local browser session or cookies. This behavior has **not been
  verified against a real Open Dental Cloud environment** - treat it as
  expected-but-unconfirmed, and validate with a pilot Cloud practice
  before promoting the shortcut to Cloud customers.

## Troubleshooting

- **Button does nothing / file not found**: verify the *Path of file to open*
  begins with `https://` and points to `od-launch.php` (not a file path).
- **Button missing from the toolbar**: confirm **Enabled** is checked and at
  least one toolbar is selected under *Add a button to these toolbars*.
- **Opens DentaTrak but not filtered to a patient**: confirm the *Path of
  file to open* ends with `?pat=[PatNum]` (brackets included) and that
  *Optional command line arguments* is **blank** - arguments placed there
  are passed to the browser as separate process arguments and never reach
  the URL. Also make sure a patient was selected before clicking.
- **"No synced lab cases" for a patient you expected**: the case may not have
  been imported yet. Imported cases appear as soon as the integration syncs
  them (real-time updates or *Import existing* from Settings → Integrations).
