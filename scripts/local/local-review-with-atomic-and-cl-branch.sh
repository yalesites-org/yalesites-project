#!/bin/bash
# This script will checkout the specified branch of atomic, then build the
# specified branch of the component library for local review.

npm run local:review-with-atomic-branch
npm run local:review-with-cl-branch
