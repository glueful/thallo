---
title: "Reuse styling with style classes"
slug: style-classes
section: guides
order: 5
summary: "Save a block's styling as a named class, apply it elsewhere, and change it once for all."
---

A style class is a named set of style and layout settings the site owns, applied to as many
blocks as you like. Change the class and every block carrying it changes with it. By the end of
this page you will have lifted one block's styling into a class, put it on other blocks, changed
it in one place, and taken it off again.

You need an entry you can open in [the Design view](../concepts/03-design-view.md), and a block
on it you have already styled.

## Save a block's styling as a class

1. Open the entry under **Content** and press **Design**.
2. Select the block on the stage and open the **Style** tab of the inspector.
3. At the foot of the tab, press **Save as style class**. The button is disabled while the block
   carries no settings of its own: a class takes the declarations the block makes, never the
   values it inherits from the theme or from another class.
4. Type a **Name** — unique on this site, in any letter case — and a **Description** if it helps.
   The dialog lists every declaration that will move, with the breakpoint each was set at.
5. Press **Save as style class**.

Thallo creates the class, clears those declarations from the block, and applies the class to it.
The block looks exactly as it did. All of that is one step in the history, so **Undo** puts the
block back; the class record stays. If the settings cannot move without changing the page,
Thallo reports **Could not lift these settings without changing the page** and changes nothing.

## Apply the class to another block

1. Select the other block and open the **Advanced** tab.
2. Under **Style classes**, open **Apply a style class…** and choose the class.

It joins the block's list, and the picker empties, ready for the next block. It offers the
site's classes that are not archived, not locked by a running job, and not already on this
block.

Blocks in the header and the footer take classes the same way: **Site › Header & footer**,
select a block, **Advanced**. **Save as style class** is not offered there.

## Which wins, the block or its class

A block lists its classes in order, and they resolve as layers beneath the block's own settings.
The block's own choice wins; below it the last class in the list wins over the one before it.
The contest is per property and per breakpoint, so a class that sets padding and text colour
loses only the properties the block itself also sets. Drag the grip beside a class to move it in
the list.

Resolution walks down from the breakpoint you are editing — lg, then md, then base — and takes
the first declaration any layer makes there. **Reset to theme** inside a class is a declaration
too: it stops resolution and hands the property back to the theme.

A class declares no capabilities, so it may carry a setting a block does not offer. On such a
block the declaration is kept and unused.

## Change the class once, for every block

Open **Settings › Style classes**. Each row gives the class's name, its description and the
properties it declares; the search box filters on name and description. **New style class**
starts an empty one.

Press the pencil on a row to edit it. **Details** holds the name and description. **Style** holds
the declarations, through the same **Style** and **Layout** tabs the inspector uses.

Press **Save** and Thallo counts the usage before it writes: how many blocks carry the class,
split into drafts, published entries, retained revisions and regions, and for each property how
many of those blocks it is active on and how many dormant. Confirm, and published pages pick the
new declarations up on their next request.

Two things stop a save, and neither loses your edits:

- Someone saved the class first. The form keeps what you typed, and the next **Save** applies on
  top of theirs.
- The class holds a value the theme's vocabulary no longer offers. It is listed under **Needs
  attention** at the top of the editor, and nothing saves until you replace or remove it.

## Take a class off one block

In the **Advanced** tab, each applied class carries two controls:

- **Detach** writes what the class contributes into the block, then removes the reference. The
  block keeps how it looks and stops following the class.
- The × removes the reference and writes nothing in its place. The block loses what the class
  was giving it.

**Detach all** appears once a block carries more than one class. If someone changes a class while
you have the page open, Thallo refuses the detach with **Style classes changed** and re-resolves
the values; review them and try again.

## Take a class off every page

The class's own page has an **Everywhere** card with the same two actions, run across the site:

- **Detach everywhere** — every block keeps how it looks.
- **Remove everywhere — changes how pages look** — the reference goes and nothing replaces it.

Both walk every draft, published entry, retained revision and region, and both are queued jobs
rather than something that finishes while you watch. The class is locked until the job ends: it
cannot be saved, archived or applied to another block meanwhile. The card reports the pass it is
on, how many documents are done, how many were refused, and why.

A job makes at most five passes. A document that someone saved while the job was rewriting it is
refused and retried on the next pass. If references remain after the fifth pass, the job is
recorded as failed and names every document still carrying the class.

### Finish a job without a queue worker

These jobs go on the `default` queue. With no worker taking that queue, the job waits. Run it
from the shell instead:

```bash
$ php glueful thallo:style-classes:run-job <job-id>
```

The command prints the job's status, the passes it made, and how many documents it finished and
refused. It also resumes a job that stopped part way. The admin does not print the id: it is the
`locked_by_job` value on the class's row in the `style_classes` table. To run these jobs without
the shell, keep a worker on the `default` queue — see
[the scheduler and the queue](../operations/03-scheduler-and-queues.md).

## Retire a class you no longer want

**Archive**, on the class's page or as the archive button on its row, keeps the definition so old
revisions still restore. An archived class leaves the picker, so it cannot be put on another
block; blocks that already carry it keep it, and still resolve through it. There is no delete.

## Check it worked

- Change one setting on the class, save it, and reload a published page that uses it. The page
  changes.
- Open a block that carries the class and look at that setting in the **Style** tab: it says the
  value came from the class.
- After a detach everywhere, the **Everywhere** card reports the job completed and the pages it
  touched look as they did before.
