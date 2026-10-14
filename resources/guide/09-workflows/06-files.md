# Files in a workflow

A file in a workflow is always a file in the Drive: your own, or the project's. Steps pass it along by its id, never as the file itself.

| Step                                   | Does                                                         |
| -------------------------------------- | ------------------------------------------------------------ |
| Telegram, LINE, WhatsApp: get the file | Fetches what was sent with a message into the Drive          |
| Save to Drive                          | Keeps a file from a web address, or text written in the step |
| Find files                             | Lists a folder, newest first, by part of a name and by kind  |
| Read a file                            | Gives a file, and a text file's words                        |
| Make a link to a file                  | An address anyone can open it at, for up to a week           |
| Write in a room                        | Shows a picture or a video in a chat room, or links a file   |
| Send a file on Telegram                | Sends one from the Drive                                     |
| Ask AI                                 | Takes up to four pictures, for a model that can see          |

A message with nothing attached fails a "get the file" step, so put an **If** on `len(trigger.files) > 0` before it. The templates **Files sent on Telegram, kept in the Drive** and **Files sent on LINE, kept in the Drive** do this for you.
