#!/bin/bash

INPUT_FILE="inputs.txt"

if [ ! -f "$INPUT_FILE" ]; then
    echo "Error: $INPUT_FILE not found!"
    exit 1
fi

# Get terminal width
TERM_WIDTH=$(tput cols)
LEFT_WIDTH=30  # Width for username + IP
MSG_WIDTH=$((TERM_WIDTH - LEFT_WIDTH - 3))  # -3 for separator spaces

# Show last modified time of the input file
LAST_MODIFIED=$(stat -c "%y" "$INPUT_FILE" | cut -d'.' -f1)
echo "Last updated: $LAST_MODIFIED"
echo

# Print header
printf "%-${LEFT_WIDTH}s   %-${MSG_WIDTH}s\n" "Username + IP" "Message"
printf "%-${LEFT_WIDTH}s   %-${MSG_WIDTH}s\n" "------------------------------" "--------------------------"

# Read file in reverse (latest messages at the top)
tac "$INPUT_FILE" | while IFS= read -r line; do
    # Parse fields
    username=$(echo "$line" | awk -F' [|][|][|] ' '{print $1}' | xargs)
    message=$(echo "$line" | awk -F' [|][|][|] ' '{print $2}' | xargs)
    ip=$(echo "$line" | awk -F' [|][|][|] ' '{print $3}' | xargs)

    # Combine username and IP
    left_column="${username} (${ip})"

    # Wrap message
    wrapped_message=$(echo "$message" | fold -sw $MSG_WIDTH)

    # Print first line
    printf "%-${LEFT_WIDTH}s | %-${MSG_WIDTH}s\n" "$left_column" "$(echo "$wrapped_message" | head -n 1)"

    # Print additional message lines
    echo "$wrapped_message" | tail -n +2 | while IFS= read -r msg_line; do
        printf "%-${LEFT_WIDTH}s | %-${MSG_WIDTH}s\n" "" "$msg_line"
    done

    # Separator
    printf "%-${LEFT_WIDTH}s-+-%-${MSG_WIDTH}s\n" "------------------------------" "-------------------------"

done | less -S

