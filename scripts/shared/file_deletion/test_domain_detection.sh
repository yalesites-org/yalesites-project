#!/bin/bash

# Test script to demonstrate enhanced domain detection
# Usage: ./test_domain_detection.sh [lando|terminus]

MODE="${1:-lando}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=== Testing Domain Detection ===${NC}"
echo "Mode: $MODE"
echo

if [ "$MODE" == "lando" ]; then
    echo -e "${YELLOW}Testing Lando Domain Detection:${NC}"
    
    echo "1. Checking .lando.local.yml for DRUSH_OPTIONS_URI..."
    if [ -f ".lando.local.yml" ]; then
        drush_uri=$(grep -E "^\s*DRUSH_OPTIONS_URI:" .lando.local.yml | sed 's/.*DRUSH_OPTIONS_URI:\s*["\x27]\?\([^"\x27]*\)["\x27]\?.*/\1/' | tr -d ' ')
        if [ -n "$drush_uri" ]; then
            echo -e "   ${GREEN}✓ Found: ${drush_uri}${NC}"
        else
            echo -e "   ${YELLOW}✗ No DRUSH_OPTIONS_URI found${NC}"
        fi
    else
        echo -e "   ${YELLOW}✗ No .lando.local.yml file${NC}"
    fi
    
    echo "2. Checking .lando.local.yml for project name..."
    if [ -f ".lando.local.yml" ]; then
        lando_name=$(grep -E "^\s*name:" .lando.local.yml | sed 's/.*name:\s*\([^#]*\).*/\1/' | tr -d ' "')
        if [ -n "$lando_name" ]; then
            constructed_url="https://${lando_name}.lndo.site"
            echo -e "   ${GREEN}✓ Found name: $lando_name${NC}"
            echo -e "   ${BLUE}   Constructed URL: $constructed_url${NC}"
            
            # Test accessibility
            if curl -s --max-time 5 --head "$constructed_url" >/dev/null 2>&1; then
                echo -e "   ${GREEN}   ✓ URL is accessible${NC}"
            else
                echo -e "   ${YELLOW}   ✗ URL not accessible${NC}"
            fi
        else
            echo -e "   ${YELLOW}✗ No name found${NC}"
        fi
    fi
    
    echo "3. Checking lando info..."
    if command -v lando >/dev/null 2>&1; then
        lando_info=$(lando info --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$lando_info" ]; then
            edge_url=$(echo "$lando_info" | grep -o '"http[^"]*\.lndo\.site[^"]*"' | head -1 | tr -d '"')
            if [ -n "$edge_url" ]; then
                echo -e "   ${GREEN}✓ Found in lando info: $edge_url${NC}"
            else
                echo -e "   ${YELLOW}✗ No .lndo.site URL in lando info${NC}"
            fi
        else
            echo -e "   ${YELLOW}✗ Could not get lando info${NC}"
        fi
    else
        echo -e "   ${YELLOW}✗ lando command not available${NC}"
    fi

elif [ "$MODE" == "terminus" ]; then
    SITE_NAME="ys-research-support-yale-edu"
    ENV="dev"
    
    echo -e "${YELLOW}Testing Terminus Domain Detection:${NC}"
    echo "Site: $SITE_NAME"
    echo "Env: $ENV"
    echo
    
    if command -v terminus >/dev/null 2>&1; then
        echo "1. Checking terminus domain:list..."
        domain_info=$(terminus domain:list "${SITE_NAME}.${ENV}" --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$domain_info" ]; then
            echo -e "   ${GREEN}✓ Got domain list from Pantheon API${NC}"
            echo "$domain_info" | jq . 2>/dev/null | head -15 || echo "$domain_info" | head -5
            
            # Extract domains
            primary_domain=$(echo "$domain_info" | grep -o '"domain":"[^"]*"' | head -1 | cut -d'"' -f4)
            if [ -n "$primary_domain" ]; then
                echo -e "   ${GREEN}✓ Primary domain: https://$primary_domain${NC}"
            fi
        else
            echo -e "   ${YELLOW}✗ Could not get domain list${NC}"
        fi
        
        echo "2. Checking terminus env:info..."
        env_info=$(terminus env:info "${SITE_NAME}.${ENV}" --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$env_info" ]; then
            echo -e "   ${GREEN}✓ Got environment info${NC}"
            env_domain=$(echo "$env_info" | grep -o '"domain":"[^"]*"' | head -1 | cut -d'"' -f4)
            if [ -n "$env_domain" ]; then
                echo -e "   ${GREEN}✓ Env domain: https://$env_domain${NC}"
            fi
        else
            echo -e "   ${YELLOW}✗ Could not get env info${NC}"
        fi
        
        echo "3. Testing standard Pantheon URL pattern..."
        standard_url="https://${ENV}-${SITE_NAME}.pantheonsite.io"
        echo -e "   ${BLUE}   Standard URL: $standard_url${NC}"
        if curl -s --max-time 10 --head "$standard_url" >/dev/null 2>&1; then
            echo -e "   ${GREEN}   ✓ Standard URL is accessible${NC}"
        else
            echo -e "   ${YELLOW}   ✗ Standard URL not accessible${NC}"
        fi
        
    else
        echo -e "   ${RED}✗ terminus command not available${NC}"
    fi
    
fi

echo
echo -e "${BLUE}=== Test Complete ===${NC}"