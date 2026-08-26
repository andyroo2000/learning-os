# Achievement art QA

This checklist is part of the Learning OS source of truth for achievement art. Review earned and locked exports at both 512 px source size and 256 CSS px presentation size before changing a visual revision.

## Production rules

- Each generated interior is a single complete scene. Grounded characters, feet, wheels, gates, furniture, and props must visibly meet or overlap their actual surface. A gap is a failure unless flight or suspension is the explicit metaphor.
- The generated scene owns character-to-environment relationships. The deterministic build owns only the shared frame, square aperture mask, earned/locked treatment, and export sizes.
- Detached feet, duplicated limbs, stray wheels, malformed props, unexplained marks, and accidental object counts are failures.
- Foreground cropping is allowed only when it reads as deliberate framing. A character's feet, face, or milestone-defining prop must not be clipped.
- Each metaphor must make sense without its title. Location-specific scenery is not used unless the location is genuinely part of the achievement.
- Generated interiors contain no border, caption, title, criterion, logo, or watermark. The shared container and client-rendered caption own those elements.
- The 1960s Japanese screen-print palette, flat geometry, paper grain, and imperfect ink edge must remain consistent across a family.

## 2026-08-26 audit result

Status: pass for all 21 earned 512 px exports and their 256 px presentation.

- Roarer: regenerated all seven character-and-background relationships together; Echo Call remains location-neutral, City Voice stands on an actual rooftop, Mountain Caller occupies an actual valley scene, Island Shaker is an island surrounded by water, and every Rocky visibly meets its terrain.
- Card Muncher: regenerated all seven food-card metaphors as complete scenes; seated tiers meet their mats, bento and table props visibly support their cards, Full Feast and Banquet Beast fully occlude lower anatomy, Bottomless uses a structurally coherent conveyor, and Moon Muncher visibly meets its ridge and held card moon.
- Matsuri Light: regenerated all seven festival metaphors as complete scenes; hanging lanterns have visible cords, posts and gates meet footings, Night Parade has exactly two road-contacting wheels, airborne lanterns are intentionally unsupported, and Eternal Sunrise connects every lantern to its cable.

Exact production complete-scene prompts and surgical cleanup prompts are recorded beside each family in `COMPLETE_SCENE_PROMPTS.md`. Framing, masking, locked-state conversion, and export sizing are deterministic build behavior, not runtime client behavior.
