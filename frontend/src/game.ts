export type Mark = 'X' | 'O'
export type Cell = Mark | '-'

export const CELL_LABELS = [
  'top left', 'top middle', 'top right',
  'middle left', 'centre', 'middle right',
  'bottom left', 'bottom middle', 'bottom right',
] as const

const LINES: ReadonlyArray<readonly [number, number, number]> = [
  [0, 1, 2], [3, 4, 5], [6, 7, 8],
  [0, 3, 6], [1, 4, 7], [2, 5, 8],
  [0, 4, 8], [2, 4, 6],
]

export const EMPTY_BOARD = '---------'

export function toMove(board: string): Mark {
  const x = board.split('X').length - 1
  const o = board.split('O').length - 1
  return x === o ? 'X' : 'O'
}

export function winningLine(board: string): readonly number[] | null {
  for (const line of LINES) {
    const [a, b, c] = line
    if (board[a] !== '-' && board[a] === board[b] && board[b] === board[c]) return line
  }
  return null
}

export function winner(board: string): Mark | null {
  const line = winningLine(board)
  return line ? (board[line[0]!] as Mark) : null
}

export function isOver(board: string): boolean {
  return winner(board) !== null || !board.includes('-')
}

export function play(board: string, cell: number): string {
  return board.slice(0, cell) + toMove(board) + board.slice(cell + 1)
}

export const other = (mark: Mark): Mark => (mark === 'X' ? 'O' : 'X')
