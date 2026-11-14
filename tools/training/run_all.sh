#!/bin/bash
set -e
cd /home/claude/training-build
mysql -uhu -php hostel < /tmp/hostel_demo_empty.sql
python3 v_foundation.py
python3 v_masters_rest.py
python3 v_flows.py
echo ALLDONE
