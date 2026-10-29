---
title: "The project board"
order: 33
---

# The project board

A project (`Base\Forge\Entity\Project`) has a board: four columns (`Base\Forge\Enum\CardColumn`: to do, in progress, in review, delivered) and its cards (`Base\Forge\Entity\Card`), which the client and the studio both move. The bundle ships the whole of it: the page, the move, the script and the stylesheet.

## What it gives

| | |
|---|---|
| `GET /projets/{id}/tableau` (`forge_project_board`) | The board on a page of its own, in the host's layout |
| `PATCH /projets/cartes/{id}` (`forge_board_card`) | A card moved: `{"column": "review", "position": 0}`, the board's token in `X-CSRF-Token` |
| `@Forge/client/project/_board.html.twig` | The board alone, to include in a page of one's own |
| `Base\Forge\Service\Board` | `move($card, $column, $position)` renumbers the columns it touches; `add($project, $card)` puts a card at the end of its column |
| `bundles/forge/js/board.js`, `bundles/forge/css/board.css` | Drag and drop and the arrow keys, each move saved at once; no dependency |

Who sees a project and who moves its cards is `ProjectVoter`'s: its client and the staff. A delivered project (`ProjectStatus::DONE`) is read-only for its client: the page carries no token and its script leaves it alone.

## In a page of one's own

```twig
{% include '@Forge/client/project/_board.html.twig' with {project: project} %}
```

| Variable | |
|---|---|
| `project` | The project |
| `editable` | Whether the cards move. Default: what `ProjectVoter::EDIT_BOARD` says of the visitor |
| `assets` | `false` when the page already loads the stylesheet and the script. Default: the include prints both |

Run `bin/console assets:install` once, as for any bundle with public files.

The script needs nothing called: every `[data-forge-board]` carrying a `data-token` is taken in charge, the ones a page brings in later too (a page swapped in without a reload). It announces what happens on the board's element:

| Event | `detail` |
|---|---|
| `forge-board:moved` | `{card, column, position}`, once the server has saved the move |
| `forge-board:error` | `{message}`, when it refused it: the card is back where it was |

A refused move is said with SweetAlert when the page has it (`window.Swal`), in a `role="alert"` paragraph of the board otherwise.

## Its look

The stylesheet sets no colour of its own beyond defaults; a site sets these on `.forge-board` or above:

```css
.forge-board {
    --forge-board-column: var(--my-surface-soft);
    --forge-board-card: var(--my-surface);
    --forge-board-soft: var(--my-ink-soft);
    --forge-board-radius: .75rem;
    --forge-board-backlog: #8a8f98;
    --forge-board-progress: #1c7ed6;
    --forge-board-review: #f08c00;
    --forge-board-done: #2f9e44;
}
```

A card carries `is-backlog`, `is-progress`, `is-review` or `is-done`; a column being dragged over, `is-over`; the card in hand, `is-dragging`.

## An application that had its own board

An application that wrote its own board before the bundle had one (a controller renumbering the cards, a Stimulus controller, the markup in its dashboard) keeps working as it is: the bundle adds routes, a template and a service, and changes nothing that was there. To move over:

1. In the dashboard's template, replace the board's markup with the include above.
2. Delete the application's own move action and its script; `Base\Forge\Service\Board` is what the action called by hand.
3. Rename what the stylesheet styled: `.kanban` is `.forge-board`, `.kanban-column` `.forge-board-column`, `.kanban-card.kanban-review` `.forge-board-card.is-review` - or drop those rules and set the custom properties.
