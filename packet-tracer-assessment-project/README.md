# Packet Tracer Assessment Workspace

This folder is a preparation workspace for turning the existing CCNA lab files into graded Packet Tracer activities.

## What Is Possible

Yes, Packet Tracer can show progress and results inside Packet Tracer itself, but that works through **Activity Wizard** activities saved as `.pka` files, not plain `.pkt` files.

That means:

- The current `76` lab files in the parent folder can be **used as source labs**.
- Each lab that needs automatic checking must be **converted into a `.pka` activity**.
- A `.pka` activity can contain:
  - instructions
  - an initial network
  - an answer network
  - assessment items
  - connectivity tests
  - feedback / scoring

## What Is Not Practical

- Merging all `76` separate labs into one single Packet Tracer file with clean per-lab grading is not the right design.
- The current `.pkt` files do **not** automatically know whether your configuration is correct.
- Packet Tracer Desktop does not provide a supported way to fully create Activity Wizard labs programmatically from outside Packet Tracer.

## Recommended Structure

Use this workflow:

1. Keep each source lab as its own `.pkt`.
2. Open one lab in Packet Tracer.
3. Build the correct final solution inside Packet Tracer.
4. Open `Extension > Activity Wizard`.
5. Define grading rules and connectivity tests.
6. Save that graded version as a `.pka` file.
7. Repeat per lab.

## Files In This Workspace

- `labs-manifest.json`
  - master list of the current source labs
- `activity-template.md`
  - repeatable checklist for converting one lab into a graded `.pka`

## Best Next Step

The best realistic path is:

- one `.pka` file per lab
- or a small grouped set such as `Security`, `Switching`, `Routing`

If you want, I can next prepare:

- a **conversion tracker** for all 76 labs in this workspace, or
- a **starter grading plan** for the first 5 labs, or
- a **naming convention** for `.pkt -> .pka` conversion.
