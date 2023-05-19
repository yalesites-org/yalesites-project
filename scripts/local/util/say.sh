#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
ENDCOLOR='\033[0m'

function _say() {
    echo -e "${GREEN}$1${ENDCOLOR}"
}

function _error() {
    echo -e "${RED}$1${ENDCOLOR}"
}
