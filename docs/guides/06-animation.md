---
title: "Animate blocks as they scroll into view"
slug: animation
section: guides
order: 6
summary: "Add entrances, stagger a group, and set a slow zoom on a picture."
---

Motion is a group of style settings like any other. A block can fade or slide in as a visitor
scrolls to it, a Container can space its children's entrances out, and a picture can drift slowly
inside its frame. You set all of it in the Style tab, and your theme needs no change.

You need an entry open in [the Design view](../concepts/03-design-view.md): select it under
**Content** and press **Design**. Motion is held still while you edit, so the page you are building
on never hides a block from you.

## Give a block an entrance

1. Select the block on the stage. The left panel's **Block** tab opens.
2. Open the **Style** tab and find the **Motion** group.
3. Choose an **Entrance**.
4. Press **Play**, beside the group's heading, to watch it once on the stage.
5. Press **Save draft**.

Every entrance fades the block in; the choice also sets where it starts from:

| Entrance | The block starts |
|---|---|
| Fade | in place |
| Fade up | 1.5rem below its place |
| Fade down | 1.5rem above its place |
| Slide left | 2rem to the right |
| Slide right | 2rem to the left |
| Zoom in | at 92% of its size |

**None** is the seventh choice and means no entrance: the block is simply there.

Motion settings are not responsive. One value covers every screen width, whichever stage preset
you are on, so the Motion group has no breakpoint row.

A few blocks have no Motion group, because a visitor never sees them arrive: **Tab** and
**Accordion item**, whose panels are hidden until opened, **Spacer**, which is nothing to see, and
**Animated text**, which animates itself.

## Set the duration, the delay and the repeat

Three more settings sit beside **Entrance**.

**Duration** is how long the entrance takes.

| Duration | Time |
|---|---|
| Fast | 300ms |
| Normal | 600ms |
| Slow | 1s |

**Delay** is the wait before it starts, counted from the moment the block scrolls into view.

| Delay | Time |
|---|---|
| None | none |
| Short | 150ms |
| Medium | 300ms |
| Long | 600ms |

Leave either unset and the entrance runs for 600ms with no delay.

**Repeat** decides what happens the second time. **Once** animates the block the first time it is
reached and then leaves it alone. **Every time** hides it again when it scrolls out of view, so it
enters afresh on the way back.

A block counts as reached when its top edge passes a line a tenth of the viewport's height above
the bottom of the window — a line, not a fraction, so a section taller than the screen enters like
anything else.

## Stagger a Container's children

The **Container** has a setting the other blocks do not: **Stagger children**, which spaces out the
entrances of the blocks it holds.

1. Select the Container and open **Style › Motion**.
2. Set **Stagger children** to **Short** (80ms between one child and the next), **Medium** (150ms)
   or **Long** (250ms).
3. Give each child an entrance of its own. Stagger only spaces entrances out; a child with no
   entrance appears at once.

The first child waits nothing, the second one step, the third two. Past the twelfth child the wait
stops growing, so a long list does not leave a visitor watching an empty page.

Stagger reaches the Container's direct children only, never their children. To cascade a row of
cards that each hold a Container, set a stagger on each one.

## Drift a picture with Ken Burns

**Ken Burns** is a slow drift of a picture inside a frame that clips it. Two blocks have such a
frame: the **Container**, whose background image or video drifts behind its content, and the
**Hero**, whose picture drifts in its media area.

| Ken Burns | The picture |
|---|---|
| Zoom in | grows from its size to 115% |
| Zoom out | shrinks from 115% to its size |
| Pan left | sits at 112% and drifts 3% to the left |
| Pan right | sits at 112% and drifts 3% to the right |

The drift takes 20 seconds, then reverses, and then the picture rests where it started. It does
not wait to be scrolled to, and it holds still while the pointer is over the frame or something
inside it has keyboard focus. **Play** runs it for eight seconds on the stage.

The **Image** block is deliberately not a frame: its figure also holds the gutters and the caption,
which a drifting picture would cover. Where you want a large picture that drifts, make it a
Container's background instead.

## See it move

The stage holds motion still: a hidden or moving block cannot be edited. Two ways to watch it for
real.

**Play**, beside the Motion group's heading, appears once the block has at least one motion setting
set. It replays that block's entrance and its drift once, in place; press it again while it runs
and it starts over. Play needs a stage, so it is offered only in the Design view — the header and
footer editor and the style class editor show the same settings without it.

The eye at the top of the Design view, **Open theme preview in a new tab**, opens the page in your
theme with exactly what the stage is showing, and there the motion runs. Scroll down to the block:
it is not there, then it arrives. That is what a visitor will get.

## Reuse the same entrance

**Save as style class**, at the foot of the Style tab, lifts a block's declarations — its motion
settings among them — into a named class you can apply to other blocks and edit in one place. See
[reuse styling with style classes](05-style-classes.md).

## What a visitor who asked for less motion sees

A visitor whose system asks for reduced motion gets every block simply shown, in place, with no
entrance and no drift. So does a visitor whose browser has no `IntersectionObserver`, and one whose
JavaScript never arrives.

Nothing can stay hidden by accident. A block with an entrance is hidden only while the page has
said that the script which reveals it is running; that script says so only after checking for
reduced motion and for `IntersectionObserver`, and if it has not reported in within three seconds
the page shows everything anyway. A page with no entrance on it loads no motion script at all.

## If nothing animates

If the block has no **Motion** group, its block type does not offer one. A block type you made
yourself gets one by ticking **Motion** in its style settings under **Settings › Block Types**; the
block types Thallo ships are set in code and shown there locked.

If entrances work in the preview but not on the live site, look at your
Content-Security-Policy. Thallo's flag is a small inline script in the page's head, and a strict
`script-src` blocks it. Allow it by its hash,
`'sha256-oITHwt56P1Z4Ld9JC9e+G5nYf5B76v5qE0R8X48Xz9E='`, which Thallo publishes as
`Thallo\Render\Motion::FLAG_SHA256`. Blocked, it sets nothing, and every block is simply shown.

Next: [reuse styling with style classes](05-style-classes.md), or
[the style settings reference](../reference/05-style-settings.md) for every setting and the CSS it
becomes.
